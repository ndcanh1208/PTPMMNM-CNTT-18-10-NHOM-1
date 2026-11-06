#include <iostream>
#include <string>
#include <algorithm>

using namespace std;

int main() {
    ios_base::sync_with_stdio(false);
    cin.tie(NULL);

    string s;
    if (getline(cin, s)) {
        if (!s.empty() && s.back() == '\r') {
            s.pop_back();
        }
        reverse(s.begin(), s.end());
        cout << s << "\n";
    }
    return 0;
}
