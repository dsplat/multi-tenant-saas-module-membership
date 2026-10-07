import { http, extractListResult, tradeApiPrefix } from '@/shared/http'

// 后端 membership_levels 原始结构：主键 membership_level_id，sort_order，status 字符串枚举
interface RawMembershipLevel {
  membership_level_id: number
  name: string
  icon: string | null
  sort_order: number
  upgrade_conditions: Record<string, any> | null
  retain_conditions: Record<string, any> | null
  benefits: Record<string, any> | null
  status: 'active' | 'inactive'
  created_at: string
  updated_at: string
}

export interface MembershipLevel {
  id: number
  name: string
  icon: string
  sortOrder: number
  upgradeConditions: Record<string, any>
  retainConditions: Record<string, any>
  benefits: Record<string, any>
  status: number
  createdAt: string
  updatedAt: string
}

function normalizeMembershipLevel(raw: RawMembershipLevel): MembershipLevel {
  return {
    id: raw.membership_level_id,
    name: raw.name,
    icon: raw.icon ?? '',
    sortOrder: raw.sort_order ?? 0,
    upgradeConditions: raw.upgrade_conditions ?? {},
    retainConditions: raw.retain_conditions ?? {},
    benefits: raw.benefits ?? {},
    status: raw.status === 'active' ? 1 : 0,
    createdAt: raw.created_at,
    updatedAt: raw.updated_at,
  }
}

// 前端字段 → 后端契约：sortOrder→sort_order、upgradeConditions→upgrade_conditions、status 数字→字符串
function toBackendPayload(data: Partial<MembershipLevel>): Record<string, unknown> {
  const payload: Record<string, unknown> = {}
  if (data.name !== undefined) payload.name = data.name
  if (data.icon !== undefined) payload.icon = data.icon
  if (data.sortOrder !== undefined) payload.sort_order = data.sortOrder
  if (data.upgradeConditions !== undefined) payload.upgrade_conditions = data.upgradeConditions
  if (data.retainConditions !== undefined) payload.retain_conditions = data.retainConditions
  if (data.benefits !== undefined) payload.benefits = data.benefits
  if (data.status !== undefined) payload.status = data.status === 1 ? 'active' : 'inactive'
  return payload
}

export interface MembershipLevelListParams {
  page: number
  pageSize: number
  name?: string
}

export interface MembershipLevelListResult {
  data: MembershipLevel[]
  total: number
}

export async function getMembershipLevelList(
  params: MembershipLevelListParams,
): Promise<MembershipLevelListResult> {
  const res = await http.get<any>(`${tradeApiPrefix()}/membership-levels`, { params })
  const { data, total } = extractListResult<RawMembershipLevel>(res)
  return { data: data.map(normalizeMembershipLevel), total }
}

export async function createMembershipLevel(data: Partial<MembershipLevel>) {
  const res = await http.post(`${tradeApiPrefix()}/membership-levels`, toBackendPayload(data))
  return res.data
}

export async function updateMembershipLevel(id: number, data: Partial<MembershipLevel>) {
  const res = await http.put(`${tradeApiPrefix()}/membership-levels/${id}`, toBackendPayload(data))
  return res.data
}

export async function deleteMembershipLevel(id: number) {
  const res = await http.delete(`${tradeApiPrefix()}/membership-levels/${id}`)
  return res.data
}

export async function getMembershipLevelBenefits(id: number) {
  const res = await http.get<any>(`${tradeApiPrefix()}/membership-levels/${id}/benefits`)
  return res.data
}

export async function updateMembershipLevelBenefits(id: number, data: Record<string, any>) {
  const res = await http.put(`${tradeApiPrefix()}/membership-levels/${id}/benefits`, data)
  return res.data
}
