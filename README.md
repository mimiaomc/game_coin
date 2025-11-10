# game_coin
机厅刷卡投币系统

## 背景
用 PN532 + Python 实现一个基于 NFC 卡的投币系统，用于模拟街机/测试场景。

附带一个陶晶驰 T1 串口屏，后台采用 php 和 MySQL。

## 当前状态
✅ 能读卡、扣余额  
❌ 偶发 `SerialException: ClearCommError failed` 或 `Did not receive expected ACK`  
🛠️ 临时解决方案：`goto loop.bat`（是的，就是那个）

## 求助方向
- 如何优雅处理 PN532 通信中断？
- Windows 下如何确保串口稳定释放？
- 是否有更可靠的重连/重试模式？

欢迎 PR、Issue、甚至吐槽！

![刷卡器](./image.png "自制的刷卡机")
