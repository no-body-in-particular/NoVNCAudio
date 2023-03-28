TARGETS=verify_pw
CFLAGS += -O2 -fPIC -static-libgcc -static

all: $(TARGETS)

verify_pw: main.o
	$(CC) $(LDFLAGS) $^ -lssl -lcrypto -o $@

main.o: main.c

clean:
	rm -f main *.o

install:
	cp verify_pw /bin/verify_pw

style:
	astyle --style=java --break-blocks=all --pad-oper --pad-oper --pad-comma --pad-header --indent-switches --delete-empty-lines -k2 -xf --add-braces -Q  -n --recursive "*.c" "*.h"
