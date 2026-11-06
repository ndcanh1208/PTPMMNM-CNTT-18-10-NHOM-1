#include <iostream>

using namespace std;

int main() {
    ios_base::sync_with_stdio(false);
    cin.tie(NULL);

    long long a, b;
    if (cin >> a >> b) {
        // Cố ý tính sai kết quả
        cout << (a + b + 1) << "\n";
    }
    return 0;
}
