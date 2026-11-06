#include <iostream>
#include <numeric>

using namespace std;

long long gcd(long long a, long long b) {
    while (b) {
        a %= b;
        swap(a, b);
    }
    return a;
}

int main() {
    ios_base::sync_with_stdio(false);
    cin.tie(NULL);

    long long a, b;
    if (cin >> a >> b) {
        long long g = gcd(a, b);
        long long l = (a / g) * b;
        cout << g << " " << l << "\n";
    }
    return 0;
}
