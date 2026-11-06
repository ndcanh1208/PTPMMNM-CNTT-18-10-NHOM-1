import sys

def main():
    content = sys.stdin.read()
    words = content.split()
    print(len(words))

if __name__ == '__main__':
    main()
