import time
import serial
import serial           # 喵～我要开始读卡卡啦！
import mysql.connector               # 去数据库里找小主人信息～
import pydirectinput                 # 偷偷帮你按投币键，嘘！
from adafruit_pn532.uart import PN532_UART   # 喵！这就是我的读卡小耳朵
from dotenv import load_dotenv        # 偷偷把密码藏在小纸条里
import os
import threading                     # 开小线程去听屏幕宝宝的摸摸～

# 今天也要好好工作，不然主人会揉我肚肚的（不是）
# 禁用额外安全措施（主人说这样才能飞快按键，嘿嘿）
pydirectinput.FAILSAFE = False

# === 把藏在 config.env 里的小糖果都拿出来喵 ===
load_dotenv("./config.env")
DB_CONFIG = {
    "host": os.getenv("DB_HOST", "localhost"),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME", "game_coin"),
}
NFC_PORT = os.getenv("NFC_PORT", "COM4")          # 读卡器宝宝住这儿～
SCREEN_PORT = os.getenv("SCREEN_PORT", "COM5")    # 屏幕宝宝的小尾巴
SCREEN_BAUD = int(os.getenv("SCREEN_BAUD", "9600"))
COIN_KEY = os.getenv("COIN_KEY", "9")             # 投币键～咻！
PLAYER1_KEY = os.getenv("PLAYER1_KEY", "i")       # 1P启动！我要玩游戏！

# === 让读卡器宝宝醒过来揉揉眼睛 ===
try:
    nfc_uart = serial.Serial(NFC_PORT, baudrate=115200, timeout=1)
    pn532 = PN532_UART(nfc_uart, debug=False)
    pn532.SAM_configuration()
    print("✅ NFC宝宝已经睁眼啦！可以开始刷啦～")
except Exception as e:
    print(f"❌ 读卡器宝宝罢工了呜呜: {e}")
    exit(1)

# === 叫醒屏幕宝宝～ ===
try:
    screen = serial.Serial(SCREEN_PORT, SCREEN_BAUD, timeout=1)
    time.sleep(2)
    print("✅ 屏幕宝宝打了个哈欠，醒啦～")
except Exception as e:
    print(f"⚠️ 屏幕宝宝还在赖床: {e}")
    screen = None

def send_command(cmd_str):
    """偷偷往屏幕宝宝嘴里塞小纸条～"""
    if screen and screen.is_open:
        try:
            cmd_bytes = cmd_str.encode('utf-8')
            screen.write(cmd_bytes + b'\xFF\xFF\xFF')   # 三连结束符＝猫猫三连爪！
            time.sleep(0.05)
        except Exception as ex:
            print(f"塞纸条失败，屏幕宝宝咬我了: {ex}")

# === 屏幕宝宝被摸摸的时候会害羞地叫哦～ ===
def listen_screen_touch():
    """24小时守着屏幕宝宝，看它啥时候被戳戳～"""
    buffer = bytearray()
   
    while True:
        if screen and screen.is_open:
            try:
                if screen.in_waiting > 0:
                    data = screen.read(screen.in_waiting)
                    buffer.extend(data)
                   
                    try:
                        text_data = buffer.decode('utf-8', errors='ignore')
                    except:
                        text_data = ""
                   
                    if len(buffer) > 0:
                        print(f"屏幕宝宝说: {buffer} → '{text_data}'")

                    if "1P" in text_data:
                        print("有人摸了1P！我要帮他按键键啦～")
                        pydirectinput.press(PLAYER1_KEY)
                        buffer.clear()   # 吃掉证据，嘿嘿
                   
                    if len(buffer) > 100:
                        buffer.clear()   # 防止宝宝吃太多撑到
                       
            except Exception as e:
                print(f"屏幕宝宝咬到舌头了: {e}")
                buffer.clear()
                time.sleep(0.1)
        time.sleep(0.01)

# 给屏幕宝宝开个小线程～
def start_screen_listener():
    if screen:
        listener_thread = threading.Thread(target=listen_screen_touch, daemon=True)
        listener_thread.start()
        print("耳朵已经贴在屏幕宝宝身上啦～随时监听摸摸～")

# === 欢迎界面，小猫猫上线！===
send_command('page 0')
send_command('t0.txt="请刷卡"')

# 版本宣言！今天也是元气满满的一天～
print("游戏币系统 版本 1.1")
print("游戏币猫猫系统启动成功！喵呜～")

# === 一些小状态～ ===
current_uid = None          # 现在盯着哪只小卡卡呀？
has_been_processed = False  # 这张卡今天被摸过了吗
miss_count = 0              # 连续没刷到会害羞哦
MISS_THRESHOLD = 3          # 刷不到三次就假装不认识啦

def show_welcome(username, coins):
    send_command('page 1')
    send_command(f't0.txt="欢迎{username}！\\r金额：{coins}"')
    time.sleep(2)
    send_command('page 0')
    send_command('t0.txt="请刷卡"')

def show_message(msg, page=2):
    send_command(f'page {page}')
    send_command(f't0.txt="{msg}"')
    time.sleep(2)
    send_command('page 0')
    send_command('t0.txt="请刷卡"')

# 启动耳朵～
start_screen_listener()

# === 主循环！猫猫开始营业啦！===
try:
    while True:
        uid_bytes = pn532.read_passive_target(timeout=0.05)
        if uid_bytes is not None:
            uid_str = ''.join(['{:02X}'.format(b) for b in uid_bytes])
            miss_count = 0
            if uid_str != current_uid:
                current_uid = uid_str
                has_been_processed = False
            if not has_been_processed:
                try:
                    conn = mysql.connector.connect(**DB_CONFIG)
                    cursor = conn.cursor(dictionary=True)
                    # 先查这张卡有没有主人～
                    cursor.execute("SELECT account_id FROM cards WHERE uid = %s", (uid_str,))
                    card = cursor.fetchone()
                    if card:
                        account_id = card['account_id']
                        cursor.execute("SELECT username FROM accounts WHERE id = %s", (account_id,))
                        acc = cursor.fetchone()
                        if not acc:
                            show_message("账号跑丢了喵……")
                        else:
                            username = acc['username']
                            # 检查有没有人想偷偷跟这张卡分手
                            cursor.execute("""
                                SELECT id FROM pending_registrations
                                WHERE type = 'unbind_card'
                                  AND username = %s
                                  AND expires_at > NOW()
                                ORDER BY created_at DESC
                                LIMIT 1
                            """, (username,))
                            pending_unbind = cursor.fetchone()
                            if pending_unbind:
                                # 帮主人把卡卡卡扔掉～
                                cursor.execute("DELETE FROM cards WHERE uid = %s", (uid_str,))
                                cursor.execute("DELETE FROM pending_registrations WHERE id = %s", (pending_unbind['id'],))
                                conn.commit()
                                print(f"用户 {username} 的卡卡飞走啦～")
                                show_message("卡片已解绑！", 1)
                            else:
                                # 正常投币！咻——
                                cursor.execute("SELECT coins FROM accounts WHERE id = %s", (account_id,))
                                account = cursor.fetchone()
                                if not account or account['coins'] <= 0:
                                    show_message("余额不足啦！快去充值喵！")
                                else:
                                    new_coins = account['coins'] - 1
                                    cursor.execute("UPDATE accounts SET coins = %s WHERE id = %s", (new_coins, account_id))
                                    cursor.execute("""
                                        INSERT INTO swipe_logs (uid, account_id, username, coins_before, coins_after, action)
                                        VALUES (%s, %s, %s, %s, %s, 'deduct')
                                    """, (uid_str, account_id, username, account['coins'], new_coins))
                                    conn.commit()
                                    pydirectinput.press(COIN_KEY)  # 偷偷投币！
                                    print(f"{username} 投币成功！剩余 {new_coins} 枚～")
                                    show_welcome(username, new_coins)
                    else:
                        # 新卡卡！看看有没有人想领养～
                        cursor.execute("""
                            SELECT id, username, type
                            FROM pending_registrations
                            WHERE expires_at > NOW()
                            ORDER BY created_at DESC
                            LIMIT 1
                        """)
                        pending = cursor.fetchone()
                        if pending:
                            if pending['type'] == 'new_user':
                                username = pending['username']
                                cursor.execute("INSERT INTO accounts (username, coins) VALUES (%s, %s)", (username, 0))
                                account_id = cursor.lastrowid
                                cursor.execute("INSERT INTO cards (uid, account_id, nickname) VALUES (%s, %s, %s)",
                                               (uid_str, account_id, "主卡"))
                                conn.commit()
                                print(f"新用户「{username}」被猫猫领养啦！")
                                show_message(f"新用户：{username}", 1)
                            elif pending['type'] == 'bind_card':
                                username = pending['username']
                                cursor.execute("SELECT id FROM accounts WHERE username = %s", (username,))
                                acc = cursor.fetchone()
                                if acc:
                                    cursor.execute("INSERT INTO cards (uid, account_id, nickname) VALUES (%s, %s, %s)",
                                                   (uid_str, acc['id'], "备用卡"))
                                    conn.commit()
                                    print(f"卡卡被「{username}」收编啦～")
                                    show_message(f"卡已绑定：{username}", 1)
                                else:
                                    show_message("账号不存在哦～")
                            cursor.execute("DELETE FROM pending_registrations WHERE id = %s", (pending['id'],))
                            conn.commit()
                        else:
                            show_message("陌生卡卡，请找管理员领养喵～")
                    cursor.close()
                    conn.close()
                    has_been_processed = True
                except Exception as e:
                    print(f"数据库宝宝生病了: {e}")
                    show_message("系统出错啦，稍后再试喵……")
        else:
            miss_count += 1
            if miss_count >= MISS_THRESHOLD and current_uid is not None:
                print("卡卡跑掉了……猫猫不认识了～")
                current_uid = None
                has_been_processed = False
        time.sleep(0.02)

except KeyboardInterrupt:
    print("\n猫猫下班啦～按Ctrl+C了，明天见！")
finally:
    try:
        nfc_uart.close()
        if screen:
            screen.close()
    except:
        pass

# 今天也超努力的！mua～
