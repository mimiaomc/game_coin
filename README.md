# game_coin
机厅刷卡投币系统

## 背景
用 PN532 + Python 实现一个基于 NFC 卡的投币系统，用于模拟街机/测试场景。

附带一个陶晶驰 T1 串口屏，后台采用 php 和 MySQL。

## 文件说明
- `admin.php` 后台管理员页面
- `user.php` 用户查询页面
- `config.env` Python 使用的软编码数据（例如数据库连接的用户名密码、投币键等）
- `config.php` 连接 config.env 和 php 页面
- `main.py` Python 主程序
- `"goto loop.bat"` 自动重启脚本
- `game_coin.sql` 数据库模板
- `game_coin.HMI` 串口屏工程文件

## 当前状态
✅ 能读卡、扣余额  
❌ 偶发 `SerialException: ClearCommError failed` 或 `Did not receive expected ACK`  
🛠️ 临时解决方案：`goto loop.bat`（是的，就是那个）

## 求助方向
- 如何优雅处理 PN532 通信中断？
- Windows 下如何确保串口稳定释放？
- 是否有更可靠的重连/重试模式？
- 由于 UID 不安全，但是作者本人不会搞 CPU 卡，所以希望帮助做 CPU 卡

欢迎 PR、Issue、甚至吐槽！

![刷卡器](./image.png "自制的刷卡机")
