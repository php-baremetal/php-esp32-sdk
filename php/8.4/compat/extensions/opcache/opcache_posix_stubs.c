/* POSIX calls OPcache links but never makes here: the preload subprocess (no opcache.preload) and the
 * file cache (no opcache.file_cache) are off. Weak, so a real implementation wins. */
#include <errno.h>
#include <grp.h>
#include <sys/types.h>
#include <sys/uio.h>
#include <unistd.h>

#define PHPBM_WEAK __attribute__((weak))

PHPBM_WEAK pid_t fork(void)
{
    errno = ENOSYS;
    return -1;
}

PHPBM_WEAK int setuid(uid_t uid)
{
    (void) uid;
    errno = ENOSYS;
    return -1;
}

PHPBM_WEAK int setgid(gid_t gid)
{
    (void) gid;
    errno = ENOSYS;
    return -1;
}

PHPBM_WEAK int initgroups(const char *user, gid_t group)
{
    (void) user;
    (void) group;
    errno = ENOSYS;
    return -1;
}

PHPBM_WEAK ssize_t writev(int fd, const struct iovec *iov, int iovcnt)
{
    (void) fd;
    (void) iov;
    (void) iovcnt;
    errno = ENOSYS;
    return -1;
}
