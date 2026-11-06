import sys

def main():
    # Cố ý in kết quả sai (sai lệch 1 đơn vị hoặc sai trên số âm)
    input_data = sys.stdin.read().split()
    if len(input_data) >= 2:
        a = int(input_data[0])
        b = int(input_data[1])
        print(a + b + 1)

if __name__ == '__main__':
    main()
