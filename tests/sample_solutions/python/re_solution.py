import sys

def main():
    input_data = sys.stdin.read().split()
    a = int(input_data[0])
    b = int(input_data[1])
    # Cố ý gây lỗi chia cho 0 khi chạy (Runtime Error)
    divisor = 0
    result = (a + b) // divisor
    print(result)

if __name__ == '__main__':
    main()
