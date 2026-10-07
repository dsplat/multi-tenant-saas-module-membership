<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>积分系统</span>
          <el-button type="primary" @click="handleCreate"> 新建规则 </el-button>
        </div>
      </template>

      <ProTable
        ref="tableRef"
        :columns="columns"
        :request="handleRequest"
        :search-config="searchConfig"
        :actions="actions"
      />
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="600px"
      :close-on-click-modal="false"
      @close="formRef?.resetFields()"
    >
      <el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px">
        <el-form-item label="规则名称" prop="name">
          <el-input v-model="formData.name" placeholder="请输入规则名称" maxlength="50" />
        </el-form-item>
        <el-form-item label="触发场景" prop="scene">
          <el-select v-model="formData.scene" placeholder="请选择触发场景" style="width: 100%">
            <el-option
              v-for="opt in sceneOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="积分值" prop="points">
          <el-input-number v-model="formData.points" :min="1" :max="99999" style="width: 100%" />
        </el-form-item>
        <el-form-item label="有效时间" prop="timeRange">
          <el-date-picker
            v-model="formData.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="formData.status">
            <el-radio :value="1"> 启用 </el-radio>
            <el-radio :value="0"> 禁用 </el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false"> 取消 </el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit"> 确定 </el-button>
      </template>
    </el-dialog>

    <!-- 积分日志抽屉 -->
    <el-drawer v-model="logVisible" title="积分日志" size="680px">
      <ProTable
        ref="logTableRef"
        :columns="logColumns"
        :request="handleLogRequest"
        :search-config="logSearchConfig"
      />
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { ref, h, reactive } from 'vue'
import { ElMessage, ElMessageBox, ElTag, type FormInstance, type FormRules } from 'element-plus'
import ProTable from '@multi-tenant-saas/console/components/common/ProTable/ProTable.vue'
import type {
  ColumnConfig,
  SearchConfig,
  ActionConfig,
  RequestParams,
  RequestResult,
} from '@multi-tenant-saas/console/components/common/ProTable/ProTable.vue'
import {
  getPointsRuleList,
  createPointsRule,
  updatePointsRule,
  deletePointsRule,
  getPointsLogList,
  type PointsRule,
  type PointsRuleListParams,
  type PointsLogListParams,
} from '@modules/Membership/api/points'

defineOptions({ name: 'PointsSystem' })

const tableRef = ref<InstanceType<typeof ProTable>>()
const logTableRef = ref<InstanceType<typeof ProTable>>()
const dialogVisible = ref(false)
const dialogTitle = ref('新建规则')
const submitting = ref(false)
const formRef = ref<FormInstance>()
const logVisible = ref(false)
const editingId = ref<number | null>(null)

const defaultFormData = {
  name: '',
  scene: '',
  points: 10,
  timeRange: [] as string[],
  status: 1,
}

const formData = reactive({ ...defaultFormData })

const formRules: FormRules = {
  name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
  scene: [{ required: true, message: '请选择触发场景', trigger: 'change' }],
  points: [{ required: true, message: '请输入积分值', trigger: 'blur' }],
  status: [{ required: true, message: '请选择状态', trigger: 'change' }],
}

const sceneOptions = [
  { label: '消费', value: 'purchase' },
  { label: '签到', value: 'sign_in' },
  { label: '邀请好友', value: 'referral' },
  { label: '手动调整', value: 'manual' },
  { label: '生日', value: 'birthday' },
]

const searchConfig: SearchConfig[] = [
  { prop: 'name', label: '规则名称', type: 'input', placeholder: '请输入规则名称' },
  {
    prop: 'status',
    label: '状态',
    type: 'select',
    placeholder: '请选择',
    options: [
      { label: '启用', value: '1' },
      { label: '禁用', value: '0' },
    ],
  },
]

const columns: ColumnConfig[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'name', label: '规则名称', minWidth: 150 },
  {
    prop: 'scene',
    label: '触发场景',
    width: 120,
    render: (row: PointsRule) => {
      const opt = sceneOptions.find((o) => o.value === row.scene)
      return h('span', null, opt?.label ?? row.scene)
    },
  },
  { prop: 'points', label: '积分值', width: 100 },
  {
    prop: 'startTime',
    label: '有效时间',
    width: 200,
    render: (row: PointsRule) =>
      h('span', null, row.startTime && row.endTime ? `${row.startTime} 至 ${row.endTime}` : '长期'),
  },
  {
    prop: 'status',
    label: '状态',
    width: 80,
    render: (row: PointsRule) =>
      h(ElTag, { type: row.status === 1 ? 'success' : 'danger' }, () =>
        row.status === 1 ? '启用' : '禁用',
      ),
  },
  { prop: 'createdAt', label: '创建时间', width: 180, sortable: true },
]

const actions: ActionConfig[] = [
  { label: '编辑', type: 'primary', onClick: (row) => handleEdit(row as PointsRule) },
  { label: '日志', type: 'info', onClick: () => handleViewLogs() },
  { label: '删除', type: 'danger', onClick: (row) => handleDelete(row as PointsRule) },
]

async function handleRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: PointsRuleListParams = {
      page: params.page,
      pageSize: params.pageSize,
    }
    if (params.name) query.name = params.name
    if (params.status !== undefined) query.status = Number(params.status)
    const res = await getPointsRuleList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取积分规则失败')
    return { data: [], total: 0 }
  }
}

function handleCreate() {
  dialogTitle.value = '新建规则'
  editingId.value = null
  Object.assign(formData, defaultFormData)
  dialogVisible.value = true
}

function handleEdit(row: PointsRule) {
  dialogTitle.value = '编辑规则'
  editingId.value = row.points_rule_id
  formData.name = row.name
  formData.scene = row.scene
  formData.points = row.points
  formData.timeRange = row.startTime && row.endTime ? [row.startTime, row.endTime] : []
  formData.status = row.status
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  const payload = {
    name: formData.name,
    scene: formData.scene,
    points: formData.points,
    startTime: formData.timeRange?.[0] ?? '',
    endTime: formData.timeRange?.[1] ?? '',
    status: formData.status,
  }

  submitting.value = true
  try {
    if (editingId.value !== null) {
      await updatePointsRule(editingId.value, payload)
    } else {
      await createPointsRule(payload)
    }
    ElMessage.success('操作成功')
    dialogVisible.value = false
    tableRef.value?.refresh()
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  } finally {
    submitting.value = false
  }
}

async function handleDelete(row: PointsRule) {
  try {
    await ElMessageBox.confirm('确定删除该积分规则吗？', '提示', { type: 'warning' })
    await deletePointsRule(row.points_rule_id)
    ElMessage.success('删除成功')
    tableRef.value?.refresh()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || '删除失败')
    }
  }
}

function handleViewLogs() {
  logVisible.value = true
}

const logSearchConfig: SearchConfig[] = [
  { prop: 'ruleId', label: '规则ID', type: 'input', placeholder: '请输入规则ID' },
]

const logColumns: ColumnConfig[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'customerName', label: '客户', minWidth: 120 },
  { prop: 'ruleName', label: '规则', minWidth: 120 },
  {
    prop: 'points',
    label: '积分',
    width: 100,
    render: (row: any) =>
      h(
        'span',
        { style: { color: row.points > 0 ? '#67c23a' : '#f56c6c' } },
        `${row.points > 0 ? '+' : ''}${row.points}`,
      ),
  },
  { prop: 'type', label: '类型', width: 80 },
  { prop: 'remark', label: '备注', minWidth: 150 },
  { prop: 'createdAt', label: '时间', width: 180, sortable: true },
]

async function handleLogRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: PointsLogListParams = {
      page: params.page,
      pageSize: params.pageSize,
    }
    if (params.ruleId) query.ruleId = Number(params.ruleId)
    const res = await getPointsLogList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取积分日志失败')
    return { data: [], total: 0 }
  }
}
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
</style>
