# MikroTik端口映射完整配置指南

## 📋 网络环境信息

**目标配置：**
- 公网IP：144.48.136.62
- Windows电脑内网IP：192.168.99.9
- 目标服务：nginx (80端口)
- 路由器：MikroTik (winbox配置)

## 🛠️ 详细配置步骤

### 第一步：登录winbox

1. 打开winbox客户端
2. 输入路由器IP：192.168.99.1
3. 输入用户名和密码登录

### 第二步：配置端口转发规则

#### 2.1 添加DSTNAT规则

**菜单路径：** IP → Firewall → NAT

**点击"+"添加新规则：**

**General标签页：**
```
Chain: dstnat
Protocol: 6 (tcp)
Dst. Port: 80
In. Interface: [选择WAN接口，通常是ether1]
```

**Action标签页：**
```
Action: dst-nat
To Addresses: 192.168.99.9
To Ports: 80
```

**点击OK保存**

#### 2.2 验证NAT规则

配置完成后应该看到类似规则：
```
#  Chain        Src-Address    Dst-Address     Protocol   Dst-Port   Action
0  dstnat       0.0.0.0/0      144.48.136.62   tcp        80         dst-nat to 192.168.99.9:80
```

### 第三步：配置防火墙规则

#### 3.1 添加Forward规则

**菜单路径：** IP → Firewall → Filter Rules

**点击"+"添加新规则：**

**General标签页：**
```
Chain: forward
Protocol: 6 (tcp)
Dst. Address: 192.168.99.9
Dst. Port: 80
```

**Action标签页：**
```
Action: accept
```

**重要：** 确保此规则位置在任何DROP规则之前！

#### 3.2 检查现有规则顺序

确保规则顺序正确：
```
1. accept规则（新添加的）
2. 其他业务规则
3. drop规则（通常在最后）
```

### 第四步：验证源NAT配置

**菜单路径：** IP → Firewall → NAT

确保存在源NAT规则：
```
Chain: srcnat
Out. Interface: [WAN接口]
Action: masquerade
```

如果不存在，添加此规则：

**General标签页：**
```
Chain: srcnat
Out. Interface: [选择WAN接口]
```

**Action标签页：**
```
Action: masquerade
```

### 第五步：Windows防火墙配置

#### 5.1 检查Windows防火墙

在Windows电脑上：

1. **打开Windows防火墙：**
   - 控制面板 → 系统和安全 → Windows Defender防火墙

2. **允许nginx通过防火墙：**
   - 点击"允许应用或功能通过Windows Defender防火墙"
   - 如果nginx不在列表中，点击"允许其他应用"添加nginx.exe

3. **或者临时关闭防火墙测试：**
   - 点击"启用或关闭Windows Defender防火墙"
   - 临时关闭公共和专用网络防火墙

#### 5.2 检查nginx配置

确认nginx配置文件中监听端口：
```nginx
server {
    listen 80;
    listen [::]:80;
    server_name _;
    
    location / {
        root html;
        index index.html;
    }
}
```

### 第六步：网络诊断命令

#### 6.1 在MikroTik上执行

**菜单路径：** New Terminal

```bash
# 查看NAT规则
/ip firewall nat print

# 查看防火墙规则
/ip firewall filter print

# 查看当前连接
/ip firewall connection print where dst-address~"192.168.99.9"

# 测试到Windows机器的连接
/ping 192.168.99.9

# 查看接口状态
/interface print
```

#### 6.2 在Windows上执行

**PowerShell管理员模式：**

```powershell
# 检查80端口监听
netstat -an | findstr :80

# 检查nginx进程
tasklist | findstr nginx

# 测试本地访问
curl http://localhost
curl http://192.168.99.9

# 检查防火墙规则
netsh advfirewall firewall show rule name=all | findstr nginx
```

## 🔧 常见问题及解决方案

### 问题1：连接超时

**可能原因：**
- NAT规则配置错误
- 防火墙阻止连接
- nginx未启动
- Windows防火墙阻止

**解决步骤：**
1. 检查MikroTik NAT规则是否正确
2. 确认防火墙规则顺序
3. 验证nginx运行状态
4. 临时关闭Windows防火墙测试

### 问题2：无法访问但端口映射正常

**检查项目：**
- nginx配置文件语法
- Windows的IIS是否占用80端口
- 其他应用程序端口冲突

### 问题3：内网可访问，外网不能访问

**检查项目：**
- 公网IP是否正确
- ISP是否限制80端口
- 路由器WAN接口配置

## 🌐 VM虚拟机端口映射规划

### 规划端口分配

假设您有多个VM需要映射：

| 服务 | 内网地址 | 内网端口 | 公网端口 | 用途 |
|------|----------|----------|----------|------|
| Windows主机nginx | 192.168.99.9 | 80 | 80 | Web服务 |
| VM1 - Web服务器 | 192.168.99.10 | 80 | 8080 | 测试环境 |
| VM2 - 数据库 | 192.168.99.11 | 3306 | 3306 | MySQL |
| VM3 - SSH服务 | 192.168.99.12 | 22 | 2222 | 远程管理 |
| VM4 - 其他服务 | 192.168.99.13 | 8000 | 8000 | API服务 |

### VM端口映射配置模板

**对于每个VM，重复以下配置：**

#### DSTNAT规则：
```
General:
- Chain: dstnat
- Protocol: 6 (tcp)
- Dst. Port: [公网端口]
- In. Interface: [WAN接口]

Action:
- Action: dst-nat
- To Addresses: [VM内网IP]
- To Ports: [VM内网端口]
```

#### 防火墙规则：
```
General:
- Chain: forward
- Protocol: 6 (tcp)
- Dst. Address: [VM内网IP]
- Dst. Port: [VM内网端口]

Action:
- Action: accept
```

## 🔍 监控和日志

### 启用日志记录

**在防火墙规则中添加日志：**
```
Action标签页：
- Action: accept
- Log: yes
- Log Prefix: "PORT80_ACCESS"
```

### 查看日志

**菜单路径：** Log

可以看到访问记录，便于故障排除。

## 📞 紧急故障排除

### 快速恢复连接

如果配置错误导致无法连接路由器：

1. **通过MAC地址连接：**
   - 在winbox中选择"Connect to"
   - 选择MAC地址连接

2. **重置到安全配置：**
   ```bash
   /system reset-configuration
   ```

3. **恢复备份：**
   ```bash
   /system backup load name=backup_filename
   ```

---

**配置完成后的测试命令：**
- 内网测试：`curl http://192.168.99.9`
- 外网测试：`curl http://144.48.136.62`
- 端口测试：`telnet 144.48.136.62 80` 