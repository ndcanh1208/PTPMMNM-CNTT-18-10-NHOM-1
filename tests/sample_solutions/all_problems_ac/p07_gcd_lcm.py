import sys
import math

def main():
    tokens = sys.stdin.read().split()
    if len(tokens) >= 2:
        a = int(tokens[0])
        b = int(tokens[1])
        g = math.gcd(a, b)
        l = (a * b) // g
        print(f"{g} {l}")

if __name__ == '__main__':
    main()
