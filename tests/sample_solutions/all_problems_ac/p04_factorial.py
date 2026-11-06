import sys
import math

def main():
    tokens = sys.stdin.read().split()
    if tokens:
        n = int(tokens[0])
        print(math.factorial(n))

if __name__ == '__main__':
    main()
