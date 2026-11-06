import sys

def main():
    line = sys.stdin.readline()
    # Loại bỏ ký tự xuống dòng ở cuối nếu có
    if line.endswith('\r\n'):
        line = line[:-2]
    elif line.endswith('\n') or line.endswith('\r'):
        line = line[:-1]
    
    print(line[::-1])

if __name__ == '__main__':
    main()
