#include <stdbool.h>
#include <limits.h>
#include <getopt.h>
#include <netinet/in.h>
#include <fcntl.h>
#include <sys/stat.h>
#include <stdio.h>
#include <unistd.h>
#include <stdlib.h>
#include <errno.h>
#include <netdb.h>
#include <string.h>
#include <err.h>
#include <sys/types.h>
#include <sys/select.h>
#include <sys/file.h>
#include <sys/ioctl.h>
#include <sys/param.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <sys/wait.h>
#include <netinet/in.h>
#include <arpa/ftp.h>
#include <arpa/inet.h>
#include <arpa/telnet.h>
#include <openssl/ssl.h>

//amount of milliseconds to wait in between send/recv attempts
#define GRACE_TIME 20
#define CONN_TIMEOUT 5
//the command we need to pipe to our socket
#define COMMAND  "gst-launch-1.0 -q -v alsasrc ! audio/x-raw, channels=2, rate=24000 !  voaacenc  ! mp4mux streamable=true fragment_duration=10 max-raw-audio-drift=400000  ! fdsink fd=1"
//audio packet average size
#define AUDIO_PACKET_SIZE 1024
//little helper macro
#define max(a, b)(a > b ? a : b)
#define BUFSIZE 65540

//macros to do base64 encoding
#define B64Char(ch) ( ( ch ) + \
 ( ( ( ch ) >25  ) * 6 )  \
 +( ( ( ch ) <=51  ) * 65 )  \
 + ( ( ( ch ) <=62  && ( ch ) >51 ) * -10 ) \
 + ( ( ( ch )  == 62 ) * -15 ) \
  + ( ( ( ch )  == 63 ) * -22 ) \
  )


#define B64INT(dest,cptr) {\
    uint32_t num=( *((uint32_t *)(cptr)));\
    uint32_t bits= (((num<<8)&0xff0000) |  ((num>>8)&0xff00) |   ((num<<24)&0xff000000))>>8; \
    dest=( B64Char(bits & 0x3F) << 24) | ( B64Char((bits>>6) & 0x3F) <<16 ) | ( B64Char((bits>>12) & 0x3F) <<8 ) | ( B64Char((bits>>18) & 0x3F) <<0 );\
   }

#define min(a,b) ( ( a ) > ( b ) ?( b ) : ( a ) )

ssize_t ws_recv(int sockfd, SSL * ssl, void * buf, size_t len) {
    if (ssl) {
        return SSL_read(ssl, buf, len);

    } else {
        return recv(sockfd, buf, len, 0);
    }
}


ssize_t ws_send(int sockfd, SSL * ssl, const void * buf, size_t len) {
    if (ssl) {
        return SSL_write(ssl, buf, len);

    } else {
        return send(sockfd, buf, len, 0);
    }
}

SSL * init_ssl_connection(SSL_CTX  * ssl_ctx, int socket) {
    SSL * ssl = SSL_new(ssl_ctx);
    SSL_set_fd(ssl, socket);
    int ret = SSL_accept(ssl);

    if (ret < 0) {
        fprintf(stderr, "Failed to accept SSL connection.");
        return NULL;
    }

    return ssl;
}

int encode_hybi(u_char const * src, size_t srclength, char * target, size_t targsize) {
    target[0] = 0x82; //binary data

    if ((int)srclength <= 0 || (srclength + 4) > targsize) {
        fprintf(stderr, "Target buffer too small.\n");
        return 0;
    }

    if (srclength <= 125) {
        target[1] = (char) srclength;
        memcpy(target + 2, src, srclength);
        return srclength + 2;

    } else if ((srclength > 125) && (srclength < 65536)) {
        target[1] = (char) 126;
        *(u_short *)&(target[2]) = htons(srclength);
        memcpy(target + 4, src, srclength);
        return srclength + 4;

    } else {
        fprintf(stderr, "Sending frames larger than 65535 bytes not supported\n");
        return -1;
    }
}

bool parse_handshake(unsigned char * key, char * handshake) {
    char * start, *end;

    if ((strlen(handshake) < 92) || (bcmp(handshake, "GET ", 4) != 0)) {
        return false;
    }

    if (! strstr(handshake, " HTTP/1.1")) {
        return false;
    }

    start = strstr(handshake, "\r\nSec-WebSocket-Key: ");

    if (!start) {
        return false;
    }

    start += 21;
    end = strstr(start, "\r\n");
    strncpy(key, start, end - start);
    key[end - start] = '\0';
    return true;
}


static void hash_key(unsigned char * key, char * target) {
    SHA_CTX c;
    unsigned char hash[21];
    SHA1_Init(&c);
    SHA1_Update(&c, key, strlen(key));
    SHA1_Update(&c, "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", 36);
    SHA1_Final(hash, &c);
    unsigned int * b64 = (unsigned int *)target;
    hash[20] = 0;
    memset(b64, 0, 29);
    B64INT(b64[0], hash);
    B64INT(b64[1], (hash + 3));
    B64INT(b64[2], (hash + 6));
    B64INT(b64[3], (hash + 9));
    B64INT(b64[4], (hash + 12));
    B64INT(b64[5], (hash + 15));
    B64INT(b64[6], (hash + 18));
    ((unsigned char *)b64)[27] = '=';
    ((unsigned char *)b64)[28] = 0;
}


int popen2(const char * cmdline,   int  * from_child, int * to_child) {
    pid_t p;
    int pipe_stdin[2], pipe_stdout[2];

    if (pipe(pipe_stdin) != 0) {
        return -1;
    }

    if (pipe(pipe_stdout) != 0) {
        return -1;
    }

    fprintf(stdout, "pipe_stdin[0] = %d, pipe_stdin[1] = %d\n", pipe_stdin[0], pipe_stdin[1]);
    fprintf(stdout, "pipe_stdout[0] = %d, pipe_stdout[1] = %d\n", pipe_stdout[0], pipe_stdout[1]);
    p = fork();

    if (p < 0) {
        return p;    /* Fork failed */
    }

    if (p == 0) { /* child */
        close(pipe_stdin[1]);
        close(pipe_stdout[0]);
        dup2(pipe_stdin[0], fileno(stdin));
        dup2(pipe_stdout[1], fileno(stdout));
        execl("/bin/sh", "sh", "-c", cmdline, (char *)0);
        perror("execl");
        exit(0);
    }

    close(pipe_stdin[0]);
    close(pipe_stdout[1]);
    *to_child = pipe_stdin[1];
    *from_child = pipe_stdout[0];
    return p;
}


//sets the non-blocking flag for an IO descriptor
void set_nonblock(int fd) {
    //retrieve all the flags for this file descriptor
    int fl = fcntl(fd, F_GETFL, 0);

    if (fl < 0) {
        fprintf(stderr, "Failed to get flags for file descriptor %d: %s\n", fd, strerror(errno));
        exit(1);
    }

    //add the non-blocking flag to this file descriptor's flags
    if (fcntl(fd, F_SETFL, fl | O_NONBLOCK) < 0) {
        fprintf(stderr, "Failed to set flags for file descriptor %d: %s\n", fd, strerror(errno));
        exit(1);
    }
}


void pipe_audio(int sockfd, SSL * ssl) {
    int   from_child = 0;
    int to_child = 0;
    ssize_t len = 0;
    ssize_t bytes = 0;
    int child_pid = popen2(COMMAND, &from_child, &to_child);
    char      cin_buf[BUFSIZE];
    char      cout_buf[BUFSIZE];

    if (child_pid <= 0) {
        fprintf(stderr, "Failed to open pipe for command\n");
        return;
    }

    set_nonblock(from_child);

    for (size_t iter = 0;; iter++) {
        bytes = read(from_child, cin_buf, AUDIO_PACKET_SIZE );

        if (bytes < 0 && errno != EWOULDBLOCK) {
            fprintf(stderr, "target process ended\n");
            break;
        }

        if (bytes > 0) {
            len = encode_hybi(cin_buf, bytes, cout_buf, BUFSIZE);

            if (len < 0) {
                fprintf(stderr, "encoding error\n");
                break;
            }

            int ret = ws_send(sockfd, ssl, cout_buf, len);

            if (ret < len ) {
                fprintf(stderr, "client closed connection\n");
                break;
            }
        }

        if (  iter % ( (CONN_TIMEOUT * 1000 ) / GRACE_TIME ) == 0) {
            uint8_t ping[] = {0x89, 0x05, 0x48, 0x65, 0x6c, 0x6c, 0x6f};

            if (ws_send(sockfd, ssl, ping, 7) < 7) {
                break;
            }
        }

        usleep(GRACE_TIME);
    }

    kill(child_pid, SIGKILL);
    close(from_child);
    close(to_child);
}

bool close_connection(int sock, SSL * ssl) {
    if (ssl) {
        SSL_free(ssl);
    }

    if (sock) {
        shutdown(sock, SHUT_RDWR);
        close(sock);
    }

    return false;
}

bool do_handshake(SSL_CTX  * ssl_ctx, int sock) {
    char handshake[4096], response[4096], key[4096], sha1[29];
    int len, offset;
    SSL * ssl = 0;
    bzero(handshake, sizeof(handshake));
    bzero(response, sizeof(response));
    bzero(key, sizeof(key));
    len = recv(sock, handshake, 1024, MSG_PEEK);
    handshake[len] = 0;

    if (len == 0) {
        fprintf(stdout, "ignoring empty handshake\n");
        return close_connection(sock, ssl);

    } else if ((bcmp(handshake, "\x16", 1) == 0) ||
               (bcmp(handshake, "\x80", 1) == 0)) {
        ssl = init_ssl_connection(ssl_ctx, sock);
    }

    offset = 0;

    for (int i = 0; i < 10; i++) {
        if (0 > (len = ws_recv(sock, ssl, handshake + offset, sizeof(handshake) - (offset + 1)))) {
            fprintf(stderr, "Read error during handshake.\n");
            return close_connection(sock, ssl);
        }

        offset += len;
        handshake[offset] = 0;

        if (0 == len) {
            fprintf(stderr, "Connection closed during handshake.\n");
            close(sock);
            return close_connection(sock, ssl);
        }

        if (strstr(handshake, "\r\n\r\n")) {
            break;
        }

        if (sizeof(handshake) <= (size_t)(offset + 1)) {
            fprintf(stderr, "Handshake data size too large.\n");
            return close_connection(sock, ssl);
        }

        if (9 == i) {
            fprintf(stderr, "Incomplete HTTP headers.\n");
            return close_connection(sock, ssl);
        }

        usleep(GRACE_TIME);
    }

    if (!parse_handshake(key, handshake)) {
        fprintf(stderr, "Failed to parse handshake.\n");
        return close_connection(sock, ssl);
    }

    hash_key(key, sha1);
    snprintf(response, sizeof(response), "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: %s\r\n\r\n", sha1);
    ws_send(sock, ssl, response, strlen(response));
    pipe_audio(sock, ssl);
    return close_connection(sock, ssl);
}



int resolve(struct in_addr * sin_addr, const char * hostname) {
    struct addrinfo * ai;
    struct addrinfo hints = {0};

    if (inet_aton(hostname, sin_addr)) {
        return 0;
    }

    hints.ai_family = AF_INET;

    if (getaddrinfo(hostname, NULL, &hints, &ai)) {
        return -1;
    }

    for (struct addrinfo * info = ai; info; info = info->ai_next) {
        if (info->ai_family == AF_INET) {
            *sin_addr = ((struct sockaddr_in *)info->ai_addr)->sin_addr;
            freeaddrinfo(ai);
            return 0;
        }
    }

    freeaddrinfo(ai);
    return -1;
}


int create_server_sock(unsigned int port) {
    int sopt = 1;
    int ret = 0;
    struct sockaddr_in serv_addr;
    int server_socket = socket(AF_INET, SOCK_STREAM, 0);

    if (server_socket < 0) {
        fprintf(stderr, "Failed to create listening socket.\n");
        return -1;
    }

    bzero((char *) &serv_addr, sizeof(serv_addr));
    serv_addr.sin_family = AF_INET;
    serv_addr.sin_port = htons(port);
    serv_addr.sin_addr.s_addr = htonl(INADDR_ANY);
    setsockopt(server_socket, SOL_SOCKET, SO_REUSEADDR, (char *)&sopt, sizeof(sopt));

    if ( ret = bind(server_socket, (struct sockaddr *) &serv_addr, sizeof(struct sockaddr)) < 0) {
        fprintf(stderr, "Failed to bind to port %u. return value: %i \n", port, errno);
        return -1;
    }

    listen(server_socket, 100);
    fprintf(stdout, "Waiting for connections on %d\n", port);
    return server_socket;
}

SSL_CTX  * init_ssl(const char * cert) {
    // Initialize openSSL
    SSL_library_init();
    OpenSSL_add_all_algorithms();
    SSL_load_error_strings();
    SSL_CTX  * ssl_ctx = ssl_ctx = SSL_CTX_new(TLS_server_method());

    if (ssl_ctx == NULL) {
        fprintf(stderr, "Failed to configure SSL context");
        return 0;
    }

    if (SSL_CTX_use_PrivateKey_file(ssl_ctx, cert, SSL_FILETYPE_PEM) <= 0) {
        fprintf(stderr, "Unable to load private key file %s\n", cert);
        return 0;
    }

    if (SSL_CTX_use_certificate_chain_file(ssl_ctx, cert) <= 0) {
        fprintf(stderr, "Unable to load certificate file %s\n", cert);
        return 0;
    }

    return ssl_ctx;
}

bool host_allowed(const char * host) {
    char * buffer = 0;
    long length;
    bool ret = false;
    FILE * f = fopen ("/etc/allowedhosts", "rb");

    if (f) {
        fseek (f, 0, SEEK_END);
        length = ftell (f);
        fseek (f, 0, SEEK_SET);
        buffer = malloc (length + 1);
        buffer[length] = 0;

        if (buffer) {
            fread (buffer, 1, length, f);
        }

        fclose (f);
    }

    if (buffer) {
        if (strstr(buffer, host)) {
            ret = true;
        }
    }

    free(buffer);
    return ret;
}

int main(int argc, char * argv[]) {
    if (3 > argc) {
        fprintf(stderr, "usage: %s certificate_chain.pem port [ip]\n", argv[0]);
        return 1;
    }

    char hostname[512] = {0};
    strcpy(hostname, "0.0.0.0");

    if (argc > 3) {
        strcpy(hostname, argv[3]);

    } else {
        if ( 0 != getenv("RFB_CLIENT_IP") ) {
            strcpy(hostname, getenv("RFB_CLIENT_IP"));
        }
    }

    signal(SIGPIPE, SIG_IGN);
    signal(SIGCHLD, SIG_IGN);
    SSL_CTX  * ssl_ctx = init_ssl(argv[1]);
    int server_socket = create_server_sock(strtol(argv[2], NULL, 10));
    struct sockaddr_in cli_addr = {0};
    socklen_t clilen = sizeof(cli_addr);

    if (server_socket <= 0) {
        if (ssl_ctx) {
            SSL_CTX_free(ssl_ctx);
        }

        return 1;
    }

    while (1) {
        int pid = 0;
        int client_socket = accept(server_socket, (struct sockaddr *) &cli_addr,  &clilen);
        char remote_host[256] = {0};

        if (client_socket < 0) {
            fprintf(stderr, "Failed to accept connection.\n");
            continue;
        }

        strcpy(remote_host, inet_ntoa(cli_addr.sin_addr));
        fprintf(stdout, "Client connected from host %s\n", remote_host);

        if ( host_allowed(remote_host) != 0) {
            fprintf(stdout, "host ( %s ) does not match bind host, dropping connection.\n", remote_host);
            close(client_socket);
            continue;
        }

        struct timeval tv = {CONN_TIMEOUT, 0};

        setsockopt(client_socket, SOL_SOCKET, SO_SNDTIMEO, (const char *)&tv, sizeof tv);

        setsockopt(client_socket, SOL_SOCKET, SO_RCVTIMEO, (const char *)&tv, sizeof tv);

        pid = fork();

        if (pid == 0) {  // handler process. not doing a new alloc works because fork generates a new stack.
            do_handshake( ssl_ctx, client_socket);
            return 0;   // Child process exits

        } else {         // parent process
            close(client_socket);
        }
    }
}
