TARGETS=tcpulse
CFLAGS += -O2 -fPIC -static-libgcc -static

all: $(TARGETS)

tcpulse: tcpulse.o
	$(CC) $(LDFLAGS) $^ -lssl -lcrypto -o $@

tcpulse.o: tcpulse.c

clean:
	rm -f tcpulse *.o

install:
	cp tcpulse /bin/tcpulse

style:
	astyle --style=java --break-blocks=all --pad-oper --pad-oper --pad-comma --pad-header --indent-switches --delete-empty-lines -k2 -xf --add-braces -Q  -n --recursive "*.c" "*.h"
