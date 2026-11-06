import sys

def main():
    # Đọc 2 số nguyên a, b từ stdin
    input_data = sys.stdin.read().split()
    if len(input_data) >= 2:
        a = int(input_data[0])
        b = int(input_data[1])
        print(a + b)

if __name__ == '__main__':
    main()
