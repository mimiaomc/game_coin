import time
import serial
import mysql.connector
import pydirectinput
from adafruit_pn532.uart import PN532_UART
from dotenv import load_dotenv
import os

# === 从 config.env 加载配置 ===
load_dotenv("config.env")

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "localhost"),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME", "game_coin"),
}

NFC_PORT = os.getenv("NFC_PORT", "COM4")
SCREEN_PORT = os.getenv("SCREEN_PORT", "COM5")
SCREEN_BAUD = int(os.getenv("SCREEN_BAUD", "9600"))
COIN_KEY = os.getenv("COIN_KEY", "9")

# === 初始化 NFC ===
try:
    nfc_uart = serial.Serial(NFC_PORT, baudrate=115200, timeout=1)
    pn532 = PN532_UART(nfc_uart, debug=False)
    pn532.SAM_configuration()
    print("✅ NFC 初始化成功")
except Exception as e:
    print(f"❌ NFC 初始化失败: {e}")
    exit(1)

# === 初始化串口屏 ===
try:
    screen = serial.Serial(SCREEN_PORT, SCREEN_BAUD, timeout=1)
    time.sleep(2)
except Exception as e:
    print(f"⚠️ 屏幕初始化失败: {e}")
    def send_command(cmd): pass
else:
    def send_command(cmd_str):
        try:
            cmd_bytes = cmd_str.encode('utf-8')
            screen.write(cmd_bytes + b'\xFF\xFF\xFF')
            time.sleep(0.05)
        except Exception as ex:
            print(f"发送指令出错: {ex}")

# === 初始化屏幕 ===
send_command('page 0')
send_command('t0.txt="请刷卡"')
print("🎮 游戏币系统启动成功！")

# === 全局状态 ===
current_uid = None
has_been_processed = False
miss_count = 0
MISS_THRESHOLD = 3

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

# === 主循环 ===
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

                    # 先查这张卡是否已绑定
                    cursor.execute("SELECT account_id FROM cards WHERE uid = %s", (uid_str,))
                    card = cursor.fetchone()

                    if card:
                        account_id = card['account_id']
                        cursor.execute("SELECT username FROM accounts WHERE id = %s", (account_id,))
                        acc = cursor.fetchone()
                        if not acc:
                            show_message("账号异常")
                        else:
                            username = acc['username']

                            # 检查是否存在针对该用户的 unbind_card 请求
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
                                # 执行解绑
                                cursor.execute("DELETE FROM cards WHERE uid = %s", (uid_str,))
                                cursor.execute("DELETE FROM pending_registrations WHERE id = %s", (pending_unbind['id'],))
                                conn.commit()
                                print(f"🔓 用户 {username} 的卡片 {uid_str} 已解绑！")
                                show_message("卡片已解绑！", 1)
                            else:
                                # 正常投币流程
                                cursor.execute("SELECT coins FROM accounts WHERE id = %s", (account_id,))
                                account = cursor.fetchone()
                                if not account or account['coins'] <= 0:
                                    show_message("余额不足！")
                                else:
                                    new_coins = account['coins'] - 1
                                    cursor.execute("UPDATE accounts SET coins = %s WHERE id = %s", (new_coins, account_id))
                                    cursor.execute("""
                                        INSERT INTO swipe_logs (uid, account_id, username, coins_before, coins_after, action)
                                        VALUES (%s, %s, %s, %s, %s, 'deduct')
                                    """, (uid_str, account_id, username, account['coins'], new_coins))
                                    conn.commit()
                                    pydirectinput.press(COIN_KEY)
                                    print(f"✅ {username} 投币成功，余额：{new_coins}")
                                    show_welcome(username, new_coins)

                    else:
                        # 卡未绑定：处理 new_user 或 bind_card
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
                                print(f"👋 新用户「{username}」激活成功！")
                                show_message(f"新用户：{username}", 1)

                            elif pending['type'] == 'bind_card':
                                username = pending['username']
                                cursor.execute("SELECT id FROM accounts WHERE username = %s", (username,))
                                acc = cursor.fetchone()
                                if acc:
                                    cursor.execute("INSERT INTO cards (uid, account_id, nickname) VALUES (%s, %s, %s)",
                                                   (uid_str, acc['id'], "备用卡"))
                                    conn.commit()
                                    print(f"🔗 卡片绑定到「{username}」")
                                    show_message(f"卡已绑定：{username}", 1)
                                else:
                                    show_message("账号不存在")

                            cursor.execute("DELETE FROM pending_registrations WHERE id = %s", (pending['id'],))
                            conn.commit()
                        else:
                            show_message("未注册，请联系管理员")

                    cursor.close()
                    conn.close()
                    has_been_processed = True

                except Exception as e:
                    print(f"❌ 数据库错误: {e}")
                    show_message("系统错误")

        else:
            miss_count += 1
            if miss_count >= MISS_THRESHOLD and current_uid is not None:
                current_uid = None
                has_been_processed = False

        time.sleep(0.02)

except KeyboardInterrupt:
    print("\n🛑 程序退出")
finally:
    try:
        nfc_uart.close()
        screen.close()
    except:
        pass