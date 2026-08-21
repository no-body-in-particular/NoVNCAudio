
#include <stdio.h>
#include <string.h>
#include <stdlib.h>

/* Main Program */
int main() {
    char buffer[512] = {0};
    char file_buffer[512] = {0};
    char pwd_file_name[512] = {0};
    snprintf(pwd_file_name, sizeof(pwd_file_name), "%s/.vnc/vnc_password", getenv("HOME"));
    fgets(buffer, 511, stdin); //read and discard username
    fgets(buffer, 511, stdin); //read password
    FILE * file = fopen ( pwd_file_name, "r" );

    if (file == 0) {
        return 1;
    }

    fgets(file_buffer, 511, file); //read password
    fclose(file);
    return strcmp(buffer, file_buffer);
}
