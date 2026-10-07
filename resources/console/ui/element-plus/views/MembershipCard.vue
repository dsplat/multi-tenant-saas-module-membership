<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>会员卡/储值卡</span>
          <el-button type="primary" @click="handleCreate"> 新建卡片 </el-button>
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
      width="680px"
      :close-on-click-modal="false"
      @close="handleDialogClose"
    >
      <el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px">
        <el-form-item label="卡片类型" prop="type">
          <el-select v-model="formData.type" placeholder="请选择卡片类型" style="width: 100%">
            <el-option
              v-for="opt in typeOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="卡片名称" prop="name">
          <el-input
            v-model="formData.name"
            placeholder="请输入卡片名称"
            maxlength="50"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="有效期类型" prop="validityType">
          <el-radio-group v-model="formData.validityType">
            <el-radio value="permanent"> 永久有效 </el-radio>
            <el-radio value="period"> 固定期限 </el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item
          v-if="formData.validityType === 'period'"
          label="有效天数"
          prop="validityDays"
        >
          <el-input-number
            v-model="formData.validityDays"
            :min="1"
            :max="36500"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item
          v-if="formData.validityType === 'period'"
          label="有效期范围"
          prop="validityRange"
        >
          <el-date-picker
            v-model="formData.validityRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item v-if="formData.type === 'stored_value'" label="储值金额" prop="storedAmount">
          <el-input-number
            v-model="formData.storedAmount"
            :min="0"
            :precision="2"
            :step="100"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="权益说明" prop="benefits">
          <el-input
            v-model="formData.benefits"
            type="textarea"
            placeholder="请输入权益说明"
            :rows="3"
            maxlength="500"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="卡面背景图" prop="coverImage">
          <div class="cover-upload-wrapper">
            <el-upload
              class="cover-uploader"
              :show-file-list="false"
              :before-upload="handleBeforeUpload"
              :http-request="handleUpload"
              accept="image/*"
            >
              <el-image
                v-if="formData.coverImage && isAllowedUrl(formData.coverImage)"
                :src="formData.coverImage"
                fit="cover"
                class="cover-preview"
              />
              <el-icon v-else class="cover-placeholder">
                <Plus />
              </el-icon>
            </el-upload>
            <div v-if="formData.coverImage" class="cover-actions">
              <el-button type="danger" link @click="formData.coverImage = ''"> 删除 </el-button>
            </div>
          </div>
        </el-form-item>
        <el-form-item label="描述" prop="description">
          <el-input
            v-model="formData.description"
            type="textarea"
            placeholder="请输入描述"
            :rows="2"
            maxlength="200"
            show-word-limit
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

    <el-drawer v-model="detailVisible" title="卡片详情" size="480px">
      <template v-if="detailData">
        <el-descriptions :column="1" border>
          <el-descriptions-item label="ID">
            {{ detailData.id }}
          </el-descriptions-item>
          <el-descriptions-item label="卡片名称">
            {{ detailData.name }}
          </el-descriptions-item>
          <el-descriptions-item label="卡片类型">
            <el-tag :type="detailData.type === 'membership' ? 'primary' : 'warning'">
              {{ detailData.type === 'membership' ? '会员卡' : '储值卡' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="有效期">
            {{
              detailData.validityType === 'permanent'
                ? '永久有效'
                : `${detailData.validityStart} 至 ${detailData.validityEnd}`
            }}
          </el-descriptions-item>
          <el-descriptions-item v-if="detailData.type === 'stored_value'" label="储值金额">
            ¥{{ detailData.storedAmount?.toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item label="权益说明">
            {{ detailData.benefits || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="卡面背景图">
            <el-image
              v-if="detailData.coverImage && isAllowedUrl(detailData.coverImage)"
              :src="detailData.coverImage"
              fit="cover"
              style="width: 120px; height: 72px; border-radius: 4px"
              :preview-src-list="[detailData.coverImage]"
            />
            <span v-else>-</span>
          </el-descriptions-item>
          <el-descriptions-item label="描述">
            {{ detailData.description || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="detailData.status === 1 ? 'success' : 'danger'">
              {{ detailData.status === 1 ? '启用' : '禁用' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="创建时间">
            {{ detailData.createdAt }}
          </el-descriptions-item>
          <el-descriptions-item label="更新时间">
            {{ detailData.updatedAt }}
          </el-descriptions-item>
        </el-descriptions>
      </template>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { ref, h, reactive } from 'vue'
import {
  ElMessage,
  ElMessageBox,
  ElImage,
  ElTag,
  ElIcon,
  type FormInstance,
  type FormRules,
  type UploadRequestOptions,
} from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ProTable from '@/components/common/ProTable/ProTable.vue'
import type {
  ColumnConfig,
  SearchConfig,
  ActionConfig,
  RequestParams,
  RequestResult,
} from '@/components/common/ProTable/ProTable.vue'
import {
  getMembershipCardList,
  getMembershipCardDetail,
  createMembershipCard,
  updateMembershipCard,
  deleteMembershipCard,
  uploadMembershipCardCover,
  type MembershipCard,
  type MembershipCardListParams,
  type CreateMembershipCardData,
  type UpdateMembershipCardData,
} from '@modules/Membership/api/membershipCard'

defineOptions({ name: 'MembershipCard' })

const tableRef = ref<InstanceType<typeof ProTable>>()
const dialogVisible = ref(false)
const dialogTitle = ref('新建卡片')
const submitting = ref(false)
const formRef = ref<FormInstance>()
const detailVisible = ref(false)
const detailData = ref<MembershipCard | null>(null)
const editingId = ref<number | null>(null)

const defaultFormData = {
  type: 'membership' as 'membership' | 'stored_value',
  name: '',
  validityType: 'permanent' as 'permanent' | 'period',
  validityDays: 365,
  validityRange: [] as string[],
  storedAmount: 0,
  benefits: '',
  coverImage: '',
  description: '',
  status: 1,
}

const formData = reactive({ ...defaultFormData })

const formRules: FormRules = {
  type: [{ required: true, message: '请选择卡片类型', trigger: 'change' }],
  name: [{ required: true, message: '请输入卡片名称', trigger: 'blur' }],
  validityType: [{ required: true, message: '请选择有效期类型', trigger: 'change' }],
  validityDays: [
    {
      validator: (
        _rule: FormRules[string],
        _value: number | undefined,
        callback: (error?: Error) => void,
      ) => {
        if (formData.validityType === 'period' && (!_value || _value < 1)) {
          callback(new Error('请输入有效天数'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
  validityRange: [
    {
      validator: (_rule: FormRules[string], value: string[], callback: (error?: Error) => void) => {
        if (formData.validityType === 'period' && (!value || value.length !== 2)) {
          callback(new Error('请选择有效期范围'))
        } else {
          callback()
        }
      },
      trigger: 'change',
    },
  ],
  status: [{ required: true, message: '请选择状态', trigger: 'change' }],
}

const typeOptions = [
  { label: '会员卡', value: 'membership' },
  { label: '储值卡', value: 'stored_value' },
]

const searchConfig: SearchConfig[] = [
  { prop: 'name', label: '卡片名称', type: 'input', placeholder: '请输入卡片名称' },
  {
    prop: 'type',
    label: '卡片类型',
    type: 'select',
    placeholder: '请选择卡片类型',
    options: typeOptions,
  },
]

const columns: ColumnConfig[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'name', label: '卡片名称', minWidth: 150 },
  {
    prop: 'type',
    label: '卡片类型',
    width: 100,
    render: (row: MembershipCard) =>
      h(ElTag, { type: row.type === 'membership' ? 'primary' : 'warning' }, () =>
        row.type === 'membership' ? '会员卡' : '储值卡',
      ),
  },
  {
    prop: 'validityType',
    label: '有效期',
    width: 160,
    render: (row: MembershipCard) =>
      h(
        'span',
        null,
        row.validityType === 'permanent'
          ? '永久有效'
          : `${row.validityStart} 至 ${row.validityEnd}`,
      ),
  },
  {
    prop: 'coverImage',
    label: '卡面图',
    width: 80,
    render: (row: MembershipCard) =>
      row.coverImage && isAllowedUrl(row.coverImage)
        ? h(ElImage, { src: row.coverImage, style: 'width: 40px; height: 24px', fit: 'cover' })
        : h('span', { style: 'color: #999' }, '-'),
  },
  {
    prop: 'storedAmount',
    label: '储值金额',
    width: 110,
    render: (row: MembershipCard) =>
      h(
        'span',
        null,
        row.type === 'stored_value' ? `¥${row.storedAmount?.toFixed(2) ?? '0.00'}` : '-',
      ),
  },
  {
    prop: 'status',
    label: '状态',
    width: 80,
    render: (row: MembershipCard) =>
      h(ElTag, { type: row.status === 1 ? 'success' : 'danger' }, () =>
        row.status === 1 ? '启用' : '禁用',
      ),
  },
  { prop: 'createdAt', label: '创建时间', width: 180, sortable: true },
]

const actions: ActionConfig[] = [
  { label: '详情', type: 'primary', onClick: (row) => handleDetail(row as MembershipCard) },
  { label: '编辑', type: 'primary', onClick: (row) => handleEdit(row as MembershipCard) },
  { label: '删除', type: 'danger', onClick: (row) => handleDelete(row as MembershipCard) },
]

async function handleRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: MembershipCardListParams = {
      page: params.page,
      pageSize: params.pageSize,
    }
    if (params.name) query.name = params.name
    if (params.type) query.type = params.type
    const res = await getMembershipCardList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取卡片列表失败')
    return { data: [], total: 0 }
  }
}

function resetForm() {
  Object.assign(formData, defaultFormData)
}

function handleCreate() {
  dialogTitle.value = '新建卡片'
  editingId.value = null
  resetForm()
  dialogVisible.value = true
}

function handleEdit(row: MembershipCard) {
  dialogTitle.value = '编辑卡片'
  editingId.value = row.id
  formData.type = row.type
  formData.name = row.name
  formData.validityType = row.validityType
  formData.validityDays = row.validityDays || 365
  formData.validityRange =
    row.validityStart && row.validityEnd ? [row.validityStart, row.validityEnd] : []
  formData.storedAmount = row.storedAmount || 0
  formData.benefits = row.benefits || ''
  formData.coverImage = row.coverImage || ''
  formData.description = row.description || ''
  formData.status = row.status
  dialogVisible.value = true
}

async function handleDetail(row: MembershipCard) {
  try {
    detailData.value = await getMembershipCardDetail(row.id)
    detailVisible.value = true
  } catch (e: any) {
    ElMessage.error(e.message || '获取详情失败')
  }
}

async function handleSubmit() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  const basePayload = {
    name: formData.name,
    type: formData.type,
    validityType: formData.validityType,
    coverImage: formData.coverImage || undefined,
    benefits: formData.benefits || undefined,
    description: formData.description || undefined,
    status: formData.status,
  }

  if (formData.validityType === 'period') {
    Object.assign(basePayload, {
      validityDays: formData.validityDays,
      ...(formData.validityRange?.length === 2
        ? { validityStart: formData.validityRange[0], validityEnd: formData.validityRange[1] }
        : {}),
    })
  }

  if (formData.type === 'stored_value') {
    Object.assign(basePayload, { storedAmount: formData.storedAmount })
  }

  submitting.value = true
  try {
    if (editingId.value !== null) {
      await updateMembershipCard(editingId.value, basePayload as UpdateMembershipCardData)
    } else {
      await createMembershipCard(basePayload as CreateMembershipCardData)
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

async function handleDelete(row: MembershipCard) {
  try {
    await ElMessageBox.confirm('确定删除该卡片吗？', '提示', { type: 'warning' })
    await deleteMembershipCard(row.id)
    ElMessage.success('删除成功')
    tableRef.value?.refresh()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || '删除失败')
    }
  }
}

function handleBeforeUpload(file: File) {
  const isImage = file.type.startsWith('image/')
  if (!isImage) {
    ElMessage.error('只能上传图片文件')
    return false
  }
  const isLt2M = file.size / 1024 / 1024 < 2
  if (!isLt2M) {
    ElMessage.error('图片大小不能超过 2MB')
    return false
  }
  return true
}

function isAllowedUrl(url: string): boolean {
  try {
    const parsed = new URL(url)
    return ['https:', 'http:'].includes(parsed.protocol)
  } catch {
    return false
  }
}

async function handleUpload(options: UploadRequestOptions) {
  try {
    const url = await uploadMembershipCardCover(options.file)
    if (!isAllowedUrl(url)) {
      throw new Error('返回的图片地址不合法')
    }
    formData.coverImage = url
    options.onSuccess({ url })
    ElMessage.success('上传成功')
  } catch (e: any) {
    options.onError(e)
    ElMessage.error(e.message || '上传失败')
  }
}

function handleDialogClose() {
  formRef.value?.resetFields()
}
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.cover-upload-wrapper {
  display: flex;
  align-items: flex-end;
  gap: 12px;
}

.cover-uploader {
  width: 148px;
  height: 88px;
  border: 1px dashed var(--el-border-color);
  border-radius: 6px;
  cursor: pointer;
  overflow: hidden;
  transition: border-color 0.3s;
}

.cover-uploader:hover {
  border-color: var(--el-color-primary);
}

.cover-preview {
  width: 148px;
  height: 88px;
}

.cover-placeholder {
  width: 148px;
  height: 88px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  color: #8c939d;
}

.cover-actions {
  padding-bottom: 4px;
}
</style>
