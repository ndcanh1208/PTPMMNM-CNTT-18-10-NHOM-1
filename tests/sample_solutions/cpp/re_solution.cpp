// File mô phỏng lỗi khi chạy trong C++ (RE - Runtime Error)
#include <iostream>
#include <stdexcept>

using namespace std;

int main() {
    long long a, b;
    cin >> a >> b;
    
    // Cố ý gây chia cho 0 hoặc ném ngoại lệ chưa xử lý
    volatile int zero = 0;
    int crash = 42 / zero;
    cout << crash << "\n";
    return 0;
}
