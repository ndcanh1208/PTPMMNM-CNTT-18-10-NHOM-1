#include <iostream>
#include <string>

using namespace std;

int main() {
    ios_base::sync_with_stdio(false);
    cin.tie(NULL);

    string word;
    long long count = 0;
    while (cin >> word) {
        count++;
    }
    cout << count << "\n";
    return 0;
}
