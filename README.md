# SmartAdminLibrary

[![Latest Stable Version](https://img.shields.io/packagist/v/zoujingli/smart-admin-library.svg)](https://packagist.org/packages/zoujingli/smart-admin-library)
[![Total Downloads](https://img.shields.io/packagist/dt/zoujingli/smart-admin-library.svg)](https://packagist.org/packages/zoujingli/smart-admin-library)
[![Monthly Downloads](https://img.shields.io/packagist/dm/zoujingli/smart-admin-library.svg)](https://packagist.org/packages/zoujingli/smart-admin-library)
[![License](https://img.shields.io/packagist/l/zoujingli/smart-admin-library.svg)](https://packagist.org/packages/zoujingli/smart-admin-library)

SmartAdminLibrary 是 SmartAdmin 生态的开源基础库 Composer 包，提供 Core CRUD 基类、统一响应、权限注解、数据范围、租户上下文、日志审计、发布数据库工具以及源码期插件 ZIP 管理命令。

## 仓库定位

| 项目 | 说明 |
|------|------|
| 仓库 | [`zoujingli/SmartAdminLibrary`](https://github.com/zoujingli/SmartAdminLibrary) |
| 可见性 | Public / Apache-2.0 开源 |
| Composer 包 | `zoujingli/smart-admin-library` |
| 面向对象 | SmartAdmin 主项目、公开插件、用户自定义插件和二次开发项目 |

## 能力范围

- `CoreController` / `CoreService` / `CoreMapper` / `CoreModel` 标准 CRUD 基座。
- JWT 认证、`#[Auth]` 权限注解、统一响应和路由 404 业务响应收敛。
- 数据范围、租户上下文、日志审计、缓存、翻译和通用助手函数。
- Release 数据库快照、升级、恢复、dry-run 以及源码期插件 ZIP 打包、安装、移除、备份、恢复命令。

## Installation

```bash
composer require zoujingli/smart-admin-library
```

## License

Apache License 2.0。
