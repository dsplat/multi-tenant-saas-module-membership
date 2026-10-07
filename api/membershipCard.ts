import { http, extractListResult, moduleApiPrefix } from '@multi-tenant-saas/console/shared/http'

export interface MembershipCard {
  id: number
  name: string
  type: 'membership' | 'stored_value'
  coverImage: string
  validityType: 'permanent' | 'period'
  validityDays: number
  validityStart: string
  validityEnd: string
  storedAmount: number
  benefits: string
  description: string
  status: number
  createdAt: string
  updatedAt: string
}

export interface MembershipCardListParams {
  page: number
  pageSize: number
  name?: string
  type?: string
  status?: number
}

export interface MembershipCardListResult {
  data: MembershipCard[]
  total: number
}

export interface CreateMembershipCardData {
  name: string
  type: 'membership' | 'stored_value'
  coverImage?: string
  validityType: 'permanent' | 'period'
  validityDays?: number
  validityStart?: string
  validityEnd?: string
  storedAmount?: number
  benefits?: string
  description?: string
  status: number
}

export interface UpdateMembershipCardData {
  name?: string
  type?: 'membership' | 'stored_value'
  coverImage?: string
  validityType?: 'permanent' | 'period'
  validityDays?: number
  validityStart?: string
  validityEnd?: string
  storedAmount?: number
  benefits?: string
  description?: string
  status?: number
}

export async function getMembershipCardList(
  params: MembershipCardListParams,
): Promise<MembershipCardListResult> {
  const res = await http.get<MembershipCard[]>(`${moduleApiPrefix('membership')}/membership-cards`, { params })
  return extractListResult(res)
}

export async function getMembershipCardDetail(id: number): Promise<MembershipCard> {
  const res = await http.get<MembershipCard>(`${moduleApiPrefix('membership')}/membership-cards/${id}`)
  return res.data
}

export async function createMembershipCard(
  data: CreateMembershipCardData,
): Promise<MembershipCard> {
  const res = await http.post<MembershipCard>(`${moduleApiPrefix('membership')}/membership-cards`, data)
  return res.data
}

export async function updateMembershipCard(
  id: number,
  data: UpdateMembershipCardData,
): Promise<MembershipCard> {
  const res = await http.put<MembershipCard>(`${moduleApiPrefix('membership')}/membership-cards/${id}`, data)
  return res.data
}

export async function deleteMembershipCard(id: number): Promise<void> {
  await http.delete(`${moduleApiPrefix('membership')}/membership-cards/${id}`)
}

export async function uploadMembershipCardCover(file: File): Promise<string> {
  const formData = new FormData()
  formData.append('file', file)
  const res = await http.post<{ url: string }>(
    `${moduleApiPrefix('membership')}/membership-cards/upload-cover`,
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return res.data.url
}
