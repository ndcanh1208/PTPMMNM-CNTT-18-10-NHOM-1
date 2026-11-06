#include <iostream>
#include <vector>
#include <algorithm>

using namespace std;

int main() {
    ios_base::sync_with_stdio(false);
    cin.tie(NULL);

    int n;
    long long x;
    if (cin >> n >> x) {
        vector<long long> a(n);
        for (int i = 0; i < n; i++) {
            cin >> a[i];
        }
        auto it = lower_bound(a.begin(), a.end(), x);
        if (it != a.end() && *it == x) {
            cout << (distance(a.begin(), it) + 1) << "\n";
        } else {
            cout << -1 << "\n";
        }
    }
    return 0;
}
