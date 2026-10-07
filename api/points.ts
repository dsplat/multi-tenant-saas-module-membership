import { http, extractListResult, moduleApiPrefix } from '@multi-tenant-saas/console/shared/http'

// 后端 points_rules 原始结构：主键 points_rule_id，trigger_type 枚举，status 字符串枚举
interface RawPointsRule {
  points_rule_id: number
  name: string
  trigger_type: 'purchase' | 'sign_in' | 'referral' | 'manual' | 'birthday'
  points: number
  conditions: Record<string, unknown> | null
  daily_limit: number | null
  total_limit: number | null
  status: 'active' | 'inactive'
  created_at: string
  updated_at: string
}

export interface PointsRule {
  id: number
  points_rule_id: number
  name: string
  scene: string
  points: number
  startTime: string
  endTime: string
  status: number
  created_at: string
  createdAt: string
  updated_at: string
  updatedAt: string
}

function normalizePointsRule(raw: RawPointsRule): PointsRule {
  return {
    id: raw.points_rule_id,
    points_rule_id: raw.points_rule_id,
    name: raw.name,
    scene: raw.trigger_type,
    points: raw.points,
    startTime: '',
    endTime: '',
    status: raw.status === 'active' ? 1 : 0,
    created_at: raw.created_at,
    createdAt: raw.created_at,
    updated_at: raw.updated_at,
    updatedAt: raw.updated_at,
  }
}

// 前端字段 → 后端契约：scene→trigger_type、status 数字→字符串
function toPointsBackendPayload(data: {
  name: string
  scene: string
  points: number
  status: number
}): Record<string, unknown> {
  return {
    name: data.name,
    trigger_type: data.scene,
    points: data.points,
    status: data.status === 1 ? 'active' : 'inactive',
  }
}

export interface PointsLog {
  id: number
  userId: number
  customerName: string
  ruleId: number
  ruleName: string
  points: number
  type: number
  remark: string
  createdAt: string
}

export interface PointsRuleListParams {
  page: number
  pageSize: number
  name?: string
  status?: number
}

export interface PointsRuleListResult {
  data: PointsRule[]
  total: number
}

export interface PointsLogListParams {
  page: number
  pageSize: number
  ruleId?: number
}

export interface PointsLogListResult {
  data: PointsLog[]
  total: number
}

export interface CreatePointsRuleData {
  name: string
  scene: string
  points: number
  startTime: string
  endTime: string
  status: number
}

export interface UpdatePointsRuleData {
  name?: string
  scene?: string
  points?: number
  startTime?: string
  endTime?: string
  status?: number
}

export async function getPointsRuleList(
  params: PointsRuleListParams,
): Promise<PointsRuleListResult> {
  const res = await http.get<any>(`${moduleApiPrefix('membership')}/points-rules`, { params })
  const { data, total } = extractListResult<RawPointsRule>(res)
  return { data: data.map(normalizePointsRule), total }
}

export async function getPointsRuleDetail(id: number) {
  const res = await http.get<RawPointsRule>(`${moduleApiPrefix('membership')}/points-rules/${id}`)
  return res.data
}

export async function createPointsRule(data: CreatePointsRuleData) {
  const res = await http.post(`${moduleApiPrefix('membership')}/points-rules`, toPointsBackendPayload(data))
  return res.data
}

export async function updatePointsRule(id: number, data: UpdatePointsRuleData) {
  const payload: Record<string, unknown> = {}
  if (data.name !== undefined) payload.name = data.name
  if (data.scene !== undefined) payload.trigger_type = data.scene
  if (data.points !== undefined) payload.points = data.points
  if (data.status !== undefined) payload.status = data.status === 1 ? 'active' : 'inactive'
  const res = await http.put(`${moduleApiPrefix('membership')}/points-rules/${id}`, payload)
  return res.data
}

export async function deletePointsRule(id: number) {
  const res = await http.delete(`${moduleApiPrefix('membership')}/points-rules/${id}`)
  return res.data
}

export async function togglePointsRule(id: number, status: number) {
  const res = await http.patch(`${moduleApiPrefix('membership')}/points-rules/${id}/toggle`, { status })
  return res.data
}

export async function getPointsLogList(params: PointsLogListParams): Promise<PointsLogListResult> {
  const res = await http.get<any>(`${moduleApiPrefix('membership')}/points-logs`, { params })
  return extractListResult(res)
}
