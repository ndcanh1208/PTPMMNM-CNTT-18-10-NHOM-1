import sys
import math

def is_prime(n: int) -> bool:
    if n <= 1:
        return False
    if n == 2:
        return True
    if n % 2 == 0:
        return False
    limit = int(math.isqrt(n))
    for i in range(3, limit + 1, 2):
        if n % i == 0:
            return False
    return True

def main():
    tokens = sys.stdin.read().split()
    if tokens:
        n = int(tokens[0])
        print("YES" if is_prime(n) else "NO")

if __name__ == '__main__':
    main()
