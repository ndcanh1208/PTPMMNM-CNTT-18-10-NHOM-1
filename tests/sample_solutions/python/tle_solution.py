import sys
import time

def main():
    input_data = sys.stdin.read().split()
    # Cố ý lặp vô hạn gây quá giới hạn thời gian (Time Limit Exceeded)
    while True:
        time.sleep(0.1)

if __name__ == '__main__':
    main()
