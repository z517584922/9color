# winbox查看WAN接口详细步骤

## 🎯 目标：找到正确的WAN接口名称

在配置端口映射时，需要在`In. Interface`字段选择正确的WAN接口。

## 📋 查看方法

### 方法一：接口列表查看

1. **在winbox主界面点击：** `Interfaces`
2. **查看接口列表**，重点关注：
   - **名称**：通常是 `ether1`、`pppoe-out1` 等
   - **状态**：必须是 `running`
   - **注释**：可能标注了 WAN、Internet 等

**常见WAN接口名称：**
```
ether1          - 物理以太网接口1（最常见）
pppoe-out1      - PPPoE拨号接口
lte1            - 4G/LTE接口
bridge-wan      - 桥接WAN接口
```

### 方法二：IP地址查看

1. **菜单路径：** `IP → Addresses`
2. **分析IP地址分配：**

```
地址示例分析：
✅ WAN接口：
   - 144.48.136.62/24  ether1    (公网IP)
   - 10.0.0.100/24     ether1    (ISP内网IP)
   - 动态获取的IP       pppoe-out1 (拨号接口)

❌ LAN接口：
   - 192.168.99.1/24   ether2    (内网网关)
   - 192.168.1.1/24    bridge    (桥接LAN)
```

### 方法三：路由表查看

1. **菜单路径：** `IP → Routes`
2. **查看默认路由：**

```
目标地址        网关          接口
0.0.0.0/0      xxx.xxx.xxx.1  ether1    ← 这是WAN接口
```

默认路由(0.0.0.0/0)指向的接口就是WAN接口。

### 方法四：DHCP客户端查看

1. **菜单路径：** `IP → DHCP Client`
2. **查看DHCP客户端配置：**

```
接口        状态     IP地址
ether1      bound    xxx.xxx.xxx.xxx  ← WAN接口
```

如果启用了DHCP客户端，那个接口就是WAN接口。

## 🔍 具体识别您的WAN接口

### 根据您的网络信息判断

**您的网络配置：**
- 公网IP：144.48.136.62
- 内网网段：192.168.99.0/24
- 网关：192.168.99.1

**最可能的配置：**
```
WAN接口: ether1
- IP地址：144.48.136.62 或者从ISP DHCP获取
- 用途：连接到互联网

LAN接口: ether2 (或bridge)  
- IP地址：192.168.99.1
- 用途：内网设备连接
```

## 🛠️ 在winbox中的具体操作

### 1. 确认WAN接口名称

**操作步骤：**
1. 打开winbox
2. 点击左侧菜单 `Interfaces`
3. 在接口列表中找到连接外网的接口
4. 记下接口名称（通常是 `ether1`）

### 2. 验证WAN接口

**检查项目：**
- [ ] 接口状态为 `running`
- [ ] 该接口有公网IP或从ISP获取IP
- [ ] 默认路由指向该接口
- [ ] 能够ping通外网

### 3. 在NAT规则中选择

**配置端口映射时：**
```
IP → Firewall → NAT
添加新规则：

General标签：
- Chain: dstnat
- Protocol: tcp  
- Dst. Port: 80
- In. Interface: [在下拉菜单中选择刚才确认的WAN接口名称]

Action标签：
- Action: dst-nat
- To Addresses: 192.168.99.9
- To Ports: 80
```

## 🚨 常见情况说明

### 情况1：只有一个ether1接口在运行
- **结论：** ether1就是WAN接口

### 情况2：有多个ether接口在运行
- **判断标准：** 查看哪个有公网IP或连接到ISP

### 情况3：使用PPPoE拨号
- **WAN接口：** `pppoe-out1`（不是ether1）
- **物理接口：** ether1（但在NAT中选择pppoe-out1）

### 情况4：使用桥接模式
- **可能名称：** `bridge-wan`、`bridge1`等
- **确认方法：** 查看该桥接接口的IP是否为公网IP

## 🔧 故障排除

### 如果不确定哪个是WAN接口：

1. **断开网线测试：**
   - 拔掉怀疑是WAN的网线
   - 看是否断网

2. **查看流量统计：**
   - `Interfaces` 中查看 TX/RX 数据量
   - WAN接口通常有较大的数据传输量

3. **ping测试：**
   - Tools → Ping
   - 测试能否通过该接口ping通外网

## 📝 配置模板

**最终在NAT规则中的配置应该是：**

```
General:
- Chain: dstnat
- Protocol: tcp
- Dst. Port: 80
- In. Interface: ether1        ← 这里填写您确认的WAN接口名称

Action:  
- Action: dst-nat
- To Addresses: 192.168.99.9
- To Ports: 80
```

---

**小提示：** 99%的情况下，WAN接口就是 `ether1`，除非您的网络使用了特殊配置。 