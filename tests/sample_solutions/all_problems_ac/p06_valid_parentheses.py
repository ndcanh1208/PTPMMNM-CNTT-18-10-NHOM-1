import sys

def is_valid(s: str) -> bool:
    mapping = {')': '(', ']': '[', '}': '{'}
    stack = []
    for ch in s:
        if ch in '({[':
            stack.append(ch)
        elif ch in mapping:
            if not stack or stack[-1] != mapping[ch]:
                return False
            stack.pop()
    return len(stack) == 0

def main():
    line = sys.stdin.readline().strip()
    if line:
        print("YES" if is_valid(line) else "NO")

if __name__ == '__main__':
    main()
