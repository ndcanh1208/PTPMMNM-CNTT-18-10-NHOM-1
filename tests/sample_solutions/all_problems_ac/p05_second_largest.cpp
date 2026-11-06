#include <iostream>
#include <vector>
#include <set>

using namespace std;

int main() {
    ios_base::sync_with_stdio(false);
    cin.tie(NULL);

    int n;
    if (cin >> n) {
        set<long long> s;
        for (int i = 0; i < n; i++) {
            long long val;
            cin >> val;
            s.insert(val);
        }
        if (s.size() < 2) {
            cout << "NONE\n";
        } else {
            auto it = s.rbegin();
            ++it; // phần tử lớn thứ 2
            cout << *it << "\n";
        }
    }
    return 0;
}
