<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Services;

use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Modules\Ai\DTOs\AiResult;
use MultiTenantSaas\Modules\Ai\Services\AiOptional;

/**
 * 会员模块 AI 增强服务
 *
 * AI 瘫痪时：PointsRuleService 规则引擎 / 人工配置照常（走 AiOptional 降级）。
 */
class MembershipAiService
{
    public function __construct(
        private readonly AiOptional $aiOptional,
    ) {}

    /**
     * 积分营销策略推荐
     *
     * @return AiResult output: ['strategies'=>array, 'reason'=>string]
     */
    public function strategyRecommend(int $tenantId, array $memberStats = []): AiResult
    {
        $fallback = ['strategies' => [], 'reason' => ''];

        return $this->aiOptional->invoke(
            category: 'membership.strategy_recommend',
            fallback: $fallback,
            aiCall: function () use ($memberStats) {
                $ai = app(AiTextServiceContract::class);
                $data = json_encode($memberStats, JSON_UNESCAPED_UNICODE);
                $prompt = "基于会员数据推荐积分营销策略：\n{$data}\n\n"
                    . '以 JSON 返回：{"strategies":[{"name":"策略名","description":"说明"}],"reason":"推荐理由"}';
                $response = $ai->complete($prompt, ['temperature' => 0.5, 'response_format' => ['type' => 'json_object']]);

                return json_decode($response->content ?? '{}', true) ?: [];
            },
            options: ['timeout_ms' => 10000],
        );
    }

    /**
     * 会员流失预测
     *
     * @return AiResult output: ['at_risk'=>array, 'factors'=>array]
     */
    public function churnPredict(int $tenantId, array $memberData = []): AiResult
    {
        $fallback = ['at_risk' => [], 'factors' => []];

        return $this->aiOptional->invoke(
            category: 'membership.churn_predict',
            fallback: $fallback,
            aiCall: function () use ($memberData) {
                $ai = app(AiTextServiceContract::class);
                $data = json_encode(array_slice($memberData, 0, 30), JSON_UNESCAPED_UNICODE);
                $prompt = "分析以下会员数据，预测流失风险：\n{$data}\n\n"
                    . '以 JSON 返回：{"at_risk":[{"member_id":1,"risk":"high"}],"factors":["风险因素"]}';
                $response = $ai->complete($prompt, ['temperature' => 0.3, 'response_format' => ['type' => 'json_object']]);

                return json_decode($response->content ?? '{}', true) ?: [];
            },
            options: ['timeout_ms' => 10000],
        );
    }
}
