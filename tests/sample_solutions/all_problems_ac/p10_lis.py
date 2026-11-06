import sys
import bisect

def main():
    tokens = sys.stdin.read().split()
    if not tokens:
        return
    n = int(tokens[0])
    a = [int(x) for x in tokens[1:n+1]]
    
    tails = []
    for x in a:
        idx = bisect.bisect_left(tails, x)
        if idx == len(tails):
            tails.append(x)
        else:
            tails[idx] = x
            
    print(len(tails))

if __name__ == '__main__':
    main()
