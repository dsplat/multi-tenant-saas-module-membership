import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // 会员等级
  { path: 'membership/levels', name: 'MembershipLevel', component: view('membership', 'MembershipLevel'), meta: { title: '会员等级' } },
  // 积分系统
  { path: 'membership/points', name: 'PointsSystem', component: view('membership', 'PointsSystem'), meta: { title: '积分系统' } },
  // 会员卡
  { path: 'membership/cards', name: 'MembershipCard', component: view('membership', 'MembershipCard'), meta: { title: '会员卡/储值卡' } },
]

export default routes
