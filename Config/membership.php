<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 路由前缀（配置插口）
    |--------------------------------------------------------------------------
    | Membership 是框架内**首个物理归位**的模块（框架原无对应物，自下游 scrm 提升），
    | 故其路由前缀提供配置插口，让下游沿用既有路由命名空间：
    | - 框架默认 'api/v1'：/api/v1/membership-levels、/api/v1/points-rules …
    | - 下游（如 scrm）设 MEMBERSHIP_ROUTE_PREFIX=api/v1/biz 即保持既有 /api/v1/biz/* URL 零变更
    |
    | 与 Course/Product 的 route_prefix（api/v1 之下的**子前缀**，如 'scrm'）不同：本模块
    | 直接覆盖 api/v1 这一整段 —— 因为下游的既有契约是 api/v1/biz 而非 api/v1/<sub>。
    | 覆写见 MembershipServiceProvider::loadModuleRoutes()。
    */
    'route_prefix' => env('MEMBERSHIP_ROUTE_PREFIX', 'api/v1'),

];
