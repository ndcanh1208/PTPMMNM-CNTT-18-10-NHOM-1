import sys
import bisect

def main():
    tokens = sys.stdin.read().split()
    if len(tokens) < 2:
        return
    n = int(tokens[0])
    x = int(tokens[1])
    arr = [int(v) for v in tokens[2:n+2]]
    
    idx = bisect.bisect_left(arr, x)
    if idx < len(arr) and arr[idx] == x:
        print(idx + 1)
    else:
        print(-1)

if __name__ == '__main__':
    main()
