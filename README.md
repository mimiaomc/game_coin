# game_coin
机厅刷卡投币系统

## 背景
用 PN532+Python 做了一个 NFC 刷卡就能自动投币的小玩具～用于模拟街机/测试场景。

附带一个陶晶驰 T1 串口屏，后台采用 php 和 MySQL。

## 文件说明
- `admin.php` 管理员后台（可以偷偷给人充钱哦）
- `user.php` 用户自己查余额的小页面
- `config.env` 猫猫的秘密小本本（数据库密码、投币键都在这里）
- `config.php` 把猫猫的小本本翻译给 php 看
- `main.py` 猫猫本体！核心程序！
- `"goto loop.bat"` 猫猫翻车自动重启专用
- `game_coin.sql` 数据库模板
- `game_coin.HMI` 串口屏工程文件

## 当前状态
- 能读卡、能扣余额、能偷偷按投币键
- 偶而会抽风报 `SerialException: ClearCommError failed` 或 `Did not receive expected ACK`（猫猫串口罢工了呜呜）
- 临时解决方案：`goto loop.bat`（是的，就是那个）

## 求助方向
- 如何优雅处理 PN532 通信中断？
- Windows 下怎么保证串口被猫猫好好释放，不卡死？
- 有没有更靠谱的 PN532 看门狗/心跳机制？
- UID 太不安全了……猫猫想升级成 CPU 15693 / CPU 卡 / DESFire，但完全不会（哭），求大佬带带我！

欢迎提 Issue、发 PR、或者直接来仓库里吸猫！
猫猫会用卖萌和投币键报答你～

![刷卡器](./image.png "自制的刷卡机")

所有半夜帮猫猫调试串口的大佬们　爱你们mua～
猫猫在这儿等你来撸～
仓库常亮，灯永远给你留着 (ˊ˘ˋ*)✧˖°
