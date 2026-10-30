<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services\Write;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Authorization\AuthorizationRequest;
use MultiTenantSaas\Authorization\AuthorizedWriteContext;
use MultiTenantSaas\Authorization\CapabilityDefinition;
use MultiTenantSaas\Authorization\WriteOutcome;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Contracts\WriteAuthorizationAdapterContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\WriteOperationJournal;
use MultiTenantSaas\Modules\Membership\Services\PointsRuleService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * adjust_points 写适配器（B2 第二批试点，ADR v2.5 §8.7）
 *
 * 约束：
 * - prepare 只做身份/目标/字段/幂等键的规范化，不产生任何写入；
 * - execute 在事务内按 (tenant_id, user_id) 锁定 users 行并重验归属，再调用
 *   {@see PointsRuleService::adjustPoints()}（与既有 handler 同一业务服务，不回退旧 handler）；
 * - operationKey 复用 {@see WriteOperationJournal}（ai_execution_audits，scope=write_tool）：
 *   同键同输入返回首次结果（不二次外写），同键异输入 conflict，占位未终态 uncertain；
 * - 本适配器只是附加门，不替代能力门 / 凭据上限 / L2 确认。
 *
 * 积分流水没有 version 列，expectedVersion 恒为 null；并发安全由「事务内锁用户行
 * + operationKey 幂等」承担，不做基于版本号的乐观锁。
 */
final class MembershipAdjustPointsWriteAdapter implements WriteAuthorizationAdapterContract
{
    /** 写适配器注册键（Tool.writeAdapter） */
    public const ADAPTER_KEY = 'membership.adjust_points';

    /** 写操作账本中的工具标识 */
    public const TOOL_SLUG = 'adjust_points';

    public const RESOURCE_TYPE = 'membership_points';

    public const RESOURCE_NAME = 'points_transactions';

    /** 允许经适配器传入的业务字段（amount 为积分增量，正负均可） */
    public const ALLOWED_FIELDS = ['user_id', 'amount', 'reason'];

    /** 调整原因落 points_transactions.description（varchar 255） */
    private const REASON_MAX_LENGTH = 255;

    /** ai_execution_audits.request_id 为 varchar(32)：operation key 必须可原样落库 */
    private const OPERATION_KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,31}$/';

    public function __construct(
        private readonly PointsRuleService $points,
        private readonly WriteOperationJournal $journal,
    ) {}

    public function prepare(AuthorizationRequest $request, array $arguments): AuthorizedWriteContext
    {
        $identity = $this->assertOperatorIdentity($request->identity);
        $tenantId = $this->targetTenantId($request);

        if ($identity->tenantId !== (string) $tenantId) {
            throw new \DomainException('membership_write_tenant_mismatch');
        }

        $this->assertKnownArguments($arguments);

        $userId = $this->positiveId($arguments['user_id'] ?? null);

        if ($userId === null) {
            throw new \DomainException('membership_write_user_id_invalid');
        }

        $payload = [
            'user_id' => $userId,
            'amount' => $this->integerArgument('amount', $arguments['amount'] ?? null),
            'reason' => $this->reason($arguments['reason'] ?? null),
        ];

        return new AuthorizedWriteContext(
            identity: $identity,
            capabilityKey: $request->capabilityKey,
            target: $request->target,
            resourceId: (string) $userId,
            resourceType: self::RESOURCE_TYPE,
            resourceName: self::RESOURCE_NAME,
            action: 'adjust',
            allowedFields: self::ALLOWED_FIELDS,
            ownershipRules: ['tenant_id' => (string) $tenantId],
            expectedVersion: null,
            operationKey: $this->operationKey($arguments['operation_key'] ?? null),
            payload: $payload,
        );
    }

    public function execute(AuthorizedWriteContext $context): WriteOutcome
    {
        $tenantId = $this->contextTenantId($context);

        if ($tenantId === null) {
            return WriteOutcome::rejected($context->operationKey, 'membership_write_context_invalid');
        }

        $userId = $this->positiveId($context->resourceId);

        if ($userId === null) {
            return WriteOutcome::rejected($context->operationKey, 'membership_write_user_id_invalid');
        }

        try {
            $claim = $this->journal->begin(
                $tenantId,
                $context->operationKey,
                self::TOOL_SLUG,
                $this->fingerprintInput($tenantId, $userId, $context),
            );
        } catch (\Throwable) {
            // 账本不可用 = 幂等/恢复语义不可知，fail-closed，不执行外部写
            return WriteOutcome::rejected($context->operationKey, 'operation_store_unavailable');
        }

        return match ($claim['decision']) {
            WriteOperationJournal::DECISION_REPLAY => WriteOutcome::success($context->operationKey, $claim['result']),
            WriteOperationJournal::DECISION_UNCERTAIN => WriteOutcome::uncertain($context->operationKey, 'operation_in_progress'),
            WriteOperationJournal::DECISION_CONFLICT => WriteOutcome::conflict($context->operationKey, 'operation_key_input_mismatch'),
            WriteOperationJournal::DECISION_ALLOW => $this->applyClaim($context, $tenantId, $userId, (int) $claim['audit_id']),
            default => WriteOutcome::uncertain($context->operationKey, 'operation_state_unknown'),
        };
    }

    /**
     * 事务内重验用户归属并执行积分调整；异常/找不到目标均回填账本失败态（同键可安全重试）。
     */
    private function applyClaim(AuthorizedWriteContext $context, int $tenantId, int $userId, int $auditId): WriteOutcome
    {
        try {
            return DB::transaction(function () use ($context, $tenantId, $userId, $auditId): WriteOutcome {
                // 显式按授权租户 + 用户 ID 锁定；用户必须属于该租户，跨租户目标在这里拒绝。
                $locked = DB::table('users')
                    ->where('user_id', $userId)
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    $this->journal->fail($auditId, 'membership_user_not_found_or_foreign');

                    return WriteOutcome::rejected($context->operationKey, 'membership_user_not_found_or_foreign');
                }

                $result = $this->points->adjustPoints(
                    $tenantId,
                    $userId,
                    (int) $context->payload['amount'],
                    (string) $context->payload['reason'],
                );

                $snapshot = [
                    'points_transaction_id' => (int) $result['id'],
                    'user_id' => $userId,
                    'type' => (string) $result['type'],
                    'amount' => (int) $result['amount'],
                    'balance_after' => (int) $result['balance_after'],
                ];

                $this->journal->succeed($auditId, $snapshot);

                return WriteOutcome::success($context->operationKey, $snapshot);
            });
        } catch (NotFoundHttpException) {
            // 服务内 validateUser 兜底（锁定后并发删用户等）：确认未生效
            $this->failClaim($auditId, 'membership_user_not_found_or_foreign');

            return WriteOutcome::rejected($context->operationKey, 'membership_user_not_found_or_foreign');
        } catch (UnprocessableEntityHttpException) {
            // 调整后余额为负：确认未生效，可修正参数后换键重试
            $this->failClaim($auditId, 'membership_adjustment_rejected');

            return WriteOutcome::rejected($context->operationKey, 'membership_adjustment_rejected');
        } catch (\Throwable) {
            $this->failClaim($auditId, 'write_failed');

            return WriteOutcome::rejected($context->operationKey, 'write_failed');
        }
    }

    /**
     * 业务字段白名单之外的键一律拒绝（含模型注入的 tenant_id 等）。
     *
     * @param  array<string, mixed>  $arguments
     */
    private function assertKnownArguments(array $arguments): void
    {
        $allowed = [...self::ALLOWED_FIELDS, 'operation_key'];

        foreach (array_keys($arguments) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new \DomainException('membership_write_field_unsupported');
            }
        }
    }

    private function assertOperatorIdentity(ExecutionIdentity $identity): ExecutionIdentity
    {
        // 积分调整是运营面能力（common.membership.adjust_points 仅声明 operator 主体）；
        // 用户/服务主体不进入本适配器，需要时另立能力与适配器，不复用运营写路径。
        if ($identity->type !== ExecutionIdentity::TYPE_OPERATOR || $identity->isAnonymous()) {
            throw new \DomainException('membership_write_identity_invalid');
        }

        $subjectId = $identity->subjectId;

        if ($subjectId === null || $subjectId === '' || ! ctype_digit($subjectId) || (int) $subjectId <= 0) {
            throw new \DomainException('membership_write_identity_invalid');
        }

        if ($identity->tenantId === null || $identity->tenantId === '' || ! ctype_digit($identity->tenantId) || (int) $identity->tenantId <= 0) {
            throw new \DomainException('membership_write_tenant_missing');
        }

        return $identity;
    }

    private function targetTenantId(AuthorizationRequest $request): int
    {
        $tenantId = $request->target->kind === CapabilityDefinition::TARGET_TENANT
            ? $request->target->tenantId()
            : null;

        if ($tenantId === null || $tenantId <= 0) {
            throw new \DomainException('membership_write_target_invalid');
        }

        return $tenantId;
    }

    private function integerArgument(string $field, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \DomainException("membership_write_{$field}_invalid");
    }

    private function reason(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \DomainException('membership_write_reason_invalid');
        }

        $reason = trim($value);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw new \DomainException('membership_write_reason_invalid');
        }

        return $reason;
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function operationKey(mixed $value): string
    {
        // 未提供时由服务端生成本次执行键；显式键仍用于跨重试去重。
        if ($value === null || $value === '') {
            return 'ap-' . bin2hex(random_bytes(12));
        }

        if (! is_string($value) || preg_match(self::OPERATION_KEY_PATTERN, $value) !== 1) {
            throw new \DomainException('membership_write_operation_key_invalid');
        }

        return $value;
    }

    private function contextTenantId(AuthorizedWriteContext $context): ?int
    {
        if ($context->identity->type !== ExecutionIdentity::TYPE_OPERATOR
            || $context->resourceType !== self::RESOURCE_TYPE
            || $context->action !== 'adjust'
            || $context->target->kind !== CapabilityDefinition::TARGET_TENANT) {
            return null;
        }

        if (array_diff(array_keys($context->payload), self::ALLOWED_FIELDS) !== []) {
            return null;
        }

        // 必填业务字段缺失/类型漂移：拒绝被手工拼装的半成品上下文
        if (array_diff(self::ALLOWED_FIELDS, array_keys($context->payload)) !== []) {
            return null;
        }

        $payloadUserId = $this->positiveId($context->payload['user_id']);

        if ($payloadUserId === null || $payloadUserId !== $this->positiveId($context->resourceId)) {
            return null;
        }

        if (! is_int($context->payload['amount']) || ! is_string($context->payload['reason'])) {
            return null;
        }

        $tenantId = $context->target->tenantId();

        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        if ($context->identity->tenantId !== (string) $tenantId) {
            return null;
        }

        if ((string) $tenantId !== (string) ($context->ownershipRules['tenant_id'] ?? '')) {
            return null;
        }

        return $tenantId;
    }

    /**
     * 幂等指纹：同键同输入 → replay；同键异输入 → conflict。
     *
     * @return array<string, mixed>
     */
    private function fingerprintInput(int $tenantId, int $userId, AuthorizedWriteContext $context): array
    {
        $fields = $context->payload;
        ksort($fields);

        return [
            'tenant_id' => $tenantId,
            'actor_id' => (string) $context->identity->subjectId,
            'user_id' => $userId,
            'action' => $context->action,
            'fields' => $fields,
        ];
    }

    private function failClaim(int $auditId, string $reason): void
    {
        try {
            $this->journal->fail($auditId, $reason);
        } catch (\Throwable) {
            // 账本回填失败不得掩盖业务结果：占位行保持 pending → 下次同键判 uncertain
        }
    }
}
