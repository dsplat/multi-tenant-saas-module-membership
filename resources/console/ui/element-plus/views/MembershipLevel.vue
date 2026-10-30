<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>会员等级</span>
          <el-button type="primary" @click="handleCreate"> 新建等级 </el-button>
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
        <el-form-item label="等级名称" prop="name">
          <el-input v-model="formData.name" placeholder="如：青铜/白银/黄金" maxlength="20" />
        </el-form-item>
        <el-form-item label="排序" prop="sortOrder">
          <el-input-number v-model="formData.sortOrder" :min="0" :max="999" style="width: 100%" />
        </el-form-item>
        <el-form-item label="升级条件">
          <el-input
            v-model="formData.upgradeConditionsText"
            type="textarea"
            placeholder="JSON 格式"
            :rows="2"
          />
        </el-form-item>
        <el-form-item label="保级条件">
          <el-input
            v-model="formData.retainConditionsText"
            type="textarea"
            placeholder="JSON 格式"
            :rows="2"
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
  getMembershipLevelList,
  createMembershipLevel,
  updateMembershipLevel,
  deleteMembershipLevel,
  type MembershipLevel,
  type MembershipLevelListParams,
} from '@modules/Membership/api/membershipLevel'

defineOptions({ name: 'MembershipLevel' })

const tableRef = ref<InstanceType<typeof ProTable>>()
const dialogVisible = ref(false)
const dialogTitle = ref('新建等级')
const submitting = ref(false)
const formRef = ref<FormInstance>()
const editingId = ref<number | null>(null)

const defaultFormData = {
  name: '',
  sortOrder: 0,
  upgradeConditionsText: '',
  retainConditionsText: '',
  status: 1,
}

const formData = reactive({ ...defaultFormData })

const formRules: FormRules = {
  name: [{ required: true, message: '请输入等级名称', trigger: 'blur' }],
  status: [{ required: true, message: '请选择状态', trigger: 'change' }],
}

const searchConfig: SearchConfig[] = [
  { prop: 'name', label: '等级名称', type: 'input', placeholder: '请输入等级名称' },
]

const columns: ColumnConfig[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'name', label: '等级名称', minWidth: 150 },
  { prop: 'sortOrder', label: '排序', width: 80 },
  {
    prop: 'status',
    label: '状态',
    width: 80,
    render: (row: MembershipLevel) =>
      h(ElTag, { type: row.status === 1 ? 'success' : 'danger' }, () =>
        row.status === 1 ? '启用' : '禁用',
      ),
  },
  { prop: 'createdAt', label: '创建时间', width: 180, sortable: true },
]

const actions: ActionConfig[] = [
  { label: '编辑', type: 'primary', onClick: (row) => handleEdit(row as MembershipLevel) },
  { label: '删除', type: 'danger', onClick: (row) => handleDelete(row as MembershipLevel) },
]

async function handleRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: MembershipLevelListParams = { page: params.page, pageSize: params.pageSize }
    if (params.name) query.name = params.name
    const res = await getMembershipLevelList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取会员等级失败')
    return { data: [], total: 0 }
  }
}

function handleCreate() {
  dialogTitle.value = '新建等级'
  editingId.value = null
  Object.assign(formData, defaultFormData)
  dialogVisible.value = true
}

function handleEdit(row: MembershipLevel) {
  dialogTitle.value = '编辑等级'
  editingId.value = row.id
  formData.name = row.name
  formData.sortOrder = row.sortOrder ?? 0
  formData.upgradeConditionsText = row.upgradeConditions
    ? JSON.stringify(row.upgradeConditions)
    : ''
  formData.retainConditionsText = row.retainConditions ? JSON.stringify(row.retainConditions) : ''
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

  let upgradeConditions: Record<string, any> = {}
  let retainConditions: Record<string, any> = {}
  try {
    if (formData.upgradeConditionsText)
      upgradeConditions = JSON.parse(formData.upgradeConditionsText)
    if (formData.retainConditionsText) retainConditions = JSON.parse(formData.retainConditionsText)
  } catch {
    ElMessage.error('条件格式错误，请输入合法 JSON')
    return
  }

  const payload = {
    name: formData.name,
    sortOrder: formData.sortOrder,
    upgradeConditions,
    retainConditions,
    status: formData.status,
  }

  submitting.value = true
  try {
    if (editingId.value !== null) {
      await updateMembershipLevel(editingId.value, payload)
    } else {
      await createMembershipLevel(payload)
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

async function handleDelete(row: MembershipLevel) {
  try {
    await ElMessageBox.confirm('确定删除该会员等级吗？', '提示', { type: 'warning' })
    await deleteMembershipLevel(row.id)
    ElMessage.success('删除成功')
    tableRef.value?.refresh()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || '删除失败')
    }
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
