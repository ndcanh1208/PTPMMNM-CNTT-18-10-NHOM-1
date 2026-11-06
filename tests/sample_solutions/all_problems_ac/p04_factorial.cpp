#include <iostream>

using namespace std;

int main() {
    ios_base::sync_with_stdio(false);
    cin.tie(NULL);

    int n;
    if (cin >> n) {
        unsigned long long ans = 1;
        for (int i = 1; i <= n; i++) {
            ans *= (unsigned long long)i;
        }
        cout << ans << "\n";
    }
    return 0;
}
