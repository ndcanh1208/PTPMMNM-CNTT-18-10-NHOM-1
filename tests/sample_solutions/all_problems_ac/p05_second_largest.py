import sys

def main():
    tokens = sys.stdin.read().split()
    if not tokens:
        return
    n = int(tokens[0])
    nums = [int(x) for x in tokens[1:n+1]]
    unique_nums = sorted(list(set(nums)), reverse=True)
    if len(unique_nums) >= 2:
        print(unique_nums[1])
    else:
        print("NONE")

if __name__ == '__main__':
    main()
