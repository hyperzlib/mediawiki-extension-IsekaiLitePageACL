# Isekai Lite Page ACL

为 MediaWiki 提供轻量级页面级访问控制列表（ACL）管理，支持编辑、移动、授权及子页面操作的细粒度权限控制。

## 安装

1. 将 `IsekaiLitePageACL` 目录放入 MediaWiki 的 `extensions/` 目录下。

2. 在 `LocalSettings.php` 中添加：

```php
wfLoadExtension( 'IsekaiLitePageACL' );

$wgGroupPermissions['sysop']['isekai-lpacl-admin'] = true; // 使 sysop 获取所有页面权限
```

3. 运行数据库更新脚本以创建数据表：

```bash
php maintenance/update.php
```

4. 运行维护脚本以构建页面贡献者表

```bash
php extensions/IsekaiLitePageACL/maintenance/ImportRevisionEditorsToPermissionTable.php --role page-contributor
```

## 功能特性

- **页面级权限控制** — 为每个页面独立设置编辑、移动、授权等权限
- **角色系统** — 预置常用角色（页面管理员、维护者、作者等），支持自定义角色
- **权限继承** — 子页面自动继承上级页面的权限配置，也可单独覆盖
- **默认权限** — 页面创建者自动获得默认权限，注册用户可配置默认行为
- **管理员豁免** — 拥有 `isekai-lpacl-admin` 权限的用户可绕过所有页面权限检查
- **权限蕴含** — 某些权限包含隐含权限（如 `grant` 蕴含 `edit`），无需重复授权
- **图形管理界面** — 通过特殊页面管理页面权限和角色（基于 OOUI）
- **侧边栏快捷入口** — 有授权权限的用户在页面侧边栏可直接进入权限管理
- **完整 API** — 提供查询和写入 API，支持外部工具集成
- **编辑冲突保护** — 基于版本号的乐观并发控制
- **权限扩展点** — 通过扩展属性注册新权限，支持配置覆盖
- **维护脚本** — 将修订历史中的编辑者批量导入权限表

## 兼容性

| 项目 | 要求 |
|------|------|
| MediaWiki | >= 1.43.0 |
| PHP | 兼容 MediaWiki 1.43 所需的 PHP 版本 |
| 数据库 | MySQL / PostgreSQL |

## 用户权限

该扩展定义了两个用户权限（User Rights）：

| 权限 | 默认授予 | 说明 |
|------|---------|------|
| `isekai-lpacl-admin` | sysop | 绕过所有页面级 ACL 检查，可授予管理员可授予的权限 |
| `isekai-lpacl-role-admin` | sysop | 管理页面权限角色（增删改查、启用/禁用） |

示例：将权限授予特定用户组

```php
$wgGroupPermissions['bureaucrat']['isekai-lpacl-admin'] = true;
```

## 配置

### 权限定义覆盖

通过 `$IsekaiLitePageACLPermissions` 配置变量可覆盖或扩展内置权限定义。

```php
// 修改现有权限
$IsekaiLitePageACLPermissions['edit'] = [
    'default_grants' => [
        'creator' => true,
        'user' => true,  // 注册用户默认也有编辑权限
    ],
];

// 添加自定义权限
$IsekaiLitePageACLPermissions['review'] = [
    'label' => 'isekai-lpacl-permission-review',
    'help' => 'isekai-lpacl-permission-review-help',
    'sort' => 400,
    'page_creator_grantable' => true,
    'admin_grantable' => true,
    'default_grants' => [
        'creator' => false,
        'user' => false,
    ],
    'implies' => [ 'edit' ],
];

// 完整替换某个权限定义
$IsekaiLitePageACLPermissions['edit'] = [
    // ... 完整定义
    'override' => true,
];
```

### 权限定义字段说明

| 字段 | 类型 | 说明 |
|------|------|------|
| `label` | string | 界面消息键，显示权限名称 |
| `help` | string | 界面消息键，权限帮助说明 |
| `sort` | int | 排序权重（数值越小越靠前） |
| `page_creator_grantable` | bool | 页面权限管理者是否可以授予此权限 |
| `admin_grantable` | bool | 管理员（isekai-lpacl-admin）是否可以授予此权限 |
| `default_grants.creator` | bool | 页面创建者默认是否拥有此权限 |
| `default_grants.user` | bool | 注册用户默认是否拥有此权限 |
| `implies` | string[] | 此权限蕴含的其他权限列表（授予此权限时自动授予） |
| `override` | bool | 设为 `true` 时完整替换内置定义，否则为合并 |

### 内置权限

| 权限键 | 排序 | 创建者默认 | 用户默认 | 管理员可授予 | 管理者可授予 | 蕴含 |
|--------|------|-----------|---------|------------|------------|------|
| `edit` | 100 | ✓ | ✗ | ✓ | ✓ | — |
| `move` | 150 | ✓ | ✗ | ✓ | ✓ | — |
| `grant` | 200 | ✓ | ✗ | ✓ | ✗ | `edit` |
| `create-subpage` | 300 | ✓ | ✗ | ✓ | ✓ | — |
| `edit-subpage` | 310 | ✓ | ✗ | ✓ | ✓ | — |
| `move-subpage` | 320 | ✓ | ✗ | ✓ | ✓ | — |

## 使用指南

### 特殊页面

#### `Special:IsekaiLitePageACL`（或别名 `Special:PageACL`）

管理指定页面的访问控制权限。用法：

```
Special:IsekaiLitePageACL/页面标题
```

功能：
- 查看当前页面的权限配置
- 切换「继承上级页面权限」
- 为单个用户添加/编辑/删除权限
- 为用户分配角色和直接权限

#### `Special:IsekaiLitePageACLRole`（或别名 `Special:PageACLRole`）

管理页面权限角色。需要 `isekai-lpacl-role-admin` 权限。

功能：
- 查看所有角色列表
- 创建新角色
- 编辑角色名称、描述和包含的权限
- 启用/禁用角色
- 彻底删除角色

### 侧边栏快捷入口

在任意页面（已有 ACL 配置或用户有 `grant` 权限）的工具箱区域会显示「页面权限」链接，点击直接跳转到该页面的 ACL 管理页面。

## 权限模型

### 权限判定流程

对用户请求的权限判定按以下顺序进行：

1. **管理员豁免** — 拥有 `isekai-lpacl-admin` 权限的用户直接通过
2. **默认权限** — 判断用户是否为页面创建者（或创建者链成员），授予对应的默认权限
3. **直接权限** — 检查该页面 ACL 中为用户单独授予的权限
4. **角色权限** — 检查该页面 ACL 中为用户分配的角色所包含的权限
5. **权限展开** — 展开所有蕴含（implies）关系
6. **Hook 扩展** — 通过 `IsekaiLpaclUserCan` 钩子允许其他扩展修改判定结果

### 继承模型

子页面的权限继承逻辑：

- **继承模式开启**（`inherit = true`）：沿着页面层级链向上查找，合并所有祖先页面的 ACL（最近祖先的配置覆盖较远祖先）
- **继承模式关闭**（`inherit = false`）：仅使用该页面的独立 ACL 配置
- **子页面操作权限**（编辑/移动子页面）：如果用户没有子页面自身的对应权限，会回退检查最近存在的上级页面对应权限（如 `edit-subpage` / `move-subpage`）
- **创建子页面**：检查最近存在的上级页面的 `create-subpage` 权限

页面移动时，扩展会自动将移动的页面及其所有子页面加入继承索引重建队列（Job），以确保继承关系正确。

### 预置角色

| 角色键 | 名称 | 包含权限 |
|--------|------|---------|
| `page-admin` | 页面管理员 | `edit`, `move`, `grant`, `create-subpage`, `edit-subpage`, `move-subpage` |
| `page-editor` | 页面维护者 | `edit`, `create-subpage`, `edit-subpage`, `move-subpage` |
| `page-contributor` | 页面作者 | `edit`, `create-subpage`, `edit-subpage` |
| `subpage-editor` | 子页面维护者 | `create-subpage`, `edit-subpage`, `move-subpage` |
| `subpage-author` | 子页面作者 | `create-subpage`, `edit-subpage` |

角色名称由界面消息定义，可通过修改 `MediaWiki:Lpacl-role-{key}-name` 自定义显示名称。

## API

### 写入 API：`action=isekaipageacl`

需要 `csrf` 令牌。

#### 设置页面 ACL

设置指定页面的权限配置。

| 参数 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `ipaaction` | ✓ | string | `setpageacl` |
| `ipapageid` | 二选一 | integer | 页面 ID |
| `ipatitle` | 二选一 | string | 页面标题 |
| `ipaversion` | ✗ | integer | 当前 ACL 版本号（用于冲突检测） |
| `ipainherit` | ✗ | boolean | 是否继承上级页面权限（默认 `false`） |
| `ipagrants` | ✗ | string | JSON 格式的授权数组 |

`ipagrants` 格式：

```json
[
  {
    "actor_id": 123,
    "roles": ["page-editor"],
    "permissions": ["edit", "move"]
  }
]
```

#### 管理角色

| 参数 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `ipaaction` | ✓ | string | `setrole` 或 `disablerole` |
| `iparolekey` | ✓ | string | 角色键名 |
| `iparoledescription` | ✗ | string | 角色描述 |
| `ipapermissions` | ✗ | string | JSON 数组格式的权限键列表 |

### 查询 API

#### `action=query&prop=isekailpacl`

查询页面的 ACL 信息。

| 参数 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `ipaclprop` | ✗ | string[] | 返回内容：`local`（本地 ACL）、`effective`（合并后有效 ACL）、`inherit`（继承链）、`version`（版本号）。默认：`local\|inherit\|version` |

#### `action=query&meta=isekailpaclconfig`

查询全局 ACL 配置，包括所有权限定义、所有角色、当前用户的角色管理权限。

## 钩子（Hooks）

### `IsekaiLpaclUserCan`

在权限判定完成后触发，允许其他扩展修改判定结果。

```php
$hookContainer->run( 'IsekaiLpaclUserCan', [ $user, $page, $permission, $decision ] );
```

- `$user` (`UserIdentity`) — 正在被检查权限的用户
- `$page` (`PageIdentity`) — 目标页面
- `$permission` (`string`) — 被检查的权限键
- `$decision` (`PermissionDecision`) — 权限判定对象，可调用 `allow()` 放行或 `deny(string $reason)` 拒绝

### MediaWiki 原生钩子

| 钩子 | 用途 |
|------|------|
| `GetUserPermissionsErrors` | 拦截编辑和移动操作，检查页面级 ACL |
| `MovePageCheckPermissions` | 拦截页面移动操作，同时检查目标位置的子页面创建权限 |
| `PageSaveComplete` | 首次创建页面时标记创建者为参与者 |
| `PageMoveComplete` | 页面移动后触发继承索引重建任务 |
| `SidebarBeforeOutput` | 在有授权权限的用户的侧边栏添加「页面权限」链接 |

## 数据库表

| 表名 | 说明 |
|------|------|
| `isekai_lpacl_page` | 每个页面的 ACL 配置（版本号、继承标志等） |
| `isekai_lpacl_page_actor` | 每个页面为每个用户/角色授予的权限 |
| `isekai_lpacl_role` | 角色定义 |
| `isekai_lpacl_participant` | 页面参与者记录（创建者等） |
| `isekai_lpacl_inherit_index` | 继承关系索引缓存 |
| `isekai_lpacl_pending_reindex` | 待处理的继承索引重建请求 |

## 维护脚本

### ImportRevisionEditorsToPermissionTable

将修订历史中的编辑者批量导入为指定角色的权限条目。

```bash
# 将所有编辑者导入为 "page-contributor" 角色
php extensions/IsekaiLitePageACL/maintenance/ImportRevisionEditorsToPermissionTable.php \
  --role page-contributor

# 仅预览导入数量（不实际写入）
php extensions/IsekaiLitePageACL/maintenance/ImportRevisionEditorsToPermissionTable.php \
  --role page-contributor --dry-run

# 覆盖已有条目
php extensions/IsekaiLitePageACL/maintenance/ImportRevisionEditorsToPermissionTable.php \
  --role page-contributor --overwrite

# 仅处理特定页面
php extensions/IsekaiLitePageACL/maintenance/ImportRevisionEditorsToPermissionTable.php \
  --role page-contributor --page-id 42

# 调整批处理大小（默认 500）
php extensions/IsekaiLitePageACL/maintenance/ImportRevisionEditorsToPermissionTable.php \
  --role page-contributor --batch-size 1000
```

## 开发

### 添加自定义权限

其他扩展可通过 `extension.json` 的 `attributes` 注册新权限：

```json
{
  "attributes": {
    "IsekaiLitePageACL": {
      "Permissions": {
        "my-permission": {
          "label": "myextension-permission-label",
          "help": "myextension-permission-help",
          "sort": 500,
          "page_creator_grantable": true,
          "admin_grantable": true,
          "default_grants": {
            "creator": false,
            "user": false
          },
          "implies": []
        }
      }
    }
  }
}
```

### 通过 Hook 集成

使用 `IsekaiLpaclUserCan` 钩子可以在其他扩展中参与权限判定。

## 许可

[MIT](COPYING)
