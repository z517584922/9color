#!/bin/bash

# SSL证书监控脚本
# 用于检查Let's Encrypt证书的到期状态
# 创建时间：2025-07-08
# 使用方法：./ssl_monitor.sh

# 配置变量
CERT_DIR="/etc/letsencrypt/live"
ALERT_DAYS=30 # 提前30天提醒
LOG_FILE="/var/log/ssl_monitor.log"

# 颜色输出函数
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# 记录日志
write_log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >>"$LOG_FILE"
}

# 检查单个证书
check_certificate() {
    local domain_dir=$1
    local domain=$(basename "$domain_dir")
    local cert_file="$domain_dir/cert.pem"

    if [ ! -f "$cert_file" ]; then
        log_error "证书文件不存在：$cert_file"
        write_log "ERROR: 证书文件不存在 - $domain"
        return 1
    fi

    # 获取证书过期时间
    local expiry_date=$(openssl x509 -enddate -noout -in "$cert_file" | cut -d= -f2)
    local expiry_timestamp=$(date -d "$expiry_date" +%s)
    local current_timestamp=$(date +%s)
    local days_until_expiry=$(((expiry_timestamp - current_timestamp) / 86400))

    echo "========================================="
    echo "域名: $domain"
    echo "证书文件: $cert_file"
    echo "过期时间: $expiry_date"
    echo "剩余天数: $days_until_expiry 天"
    echo "========================================="

    if [ $days_until_expiry -lt 0 ]; then
        log_error "证书已过期！过期了 $((days_until_expiry * -1)) 天"
        write_log "EXPIRED: $domain - 过期 $((days_until_expiry * -1)) 天"
        return 2
    elif [ $days_until_expiry -le $ALERT_DAYS ]; then
        log_warning "证书即将过期！还有 $days_until_expiry 天"
        write_log "WARNING: $domain - 即将过期 $days_until_expiry 天"
        return 1
    else
        log_success "证书状态正常，还有 $days_until_expiry 天过期"
        write_log "OK: $domain - 正常，$days_until_expiry 天后过期"
        return 0
    fi
}

# 测试HTTPS访问
test_https_access() {
    local domain=$1

    if curl -I -k "https://$domain/" >/dev/null 2>&1; then
        log_success "HTTPS访问正常"
        write_log "HTTPS_OK: $domain - 访问正常"
        return 0
    else
        log_error "HTTPS访问失败"
        write_log "HTTPS_ERROR: $domain - 访问失败"
        return 1
    fi
}

# 检查证书自动续期状态
check_auto_renewal() {
    log_info "检查自动续期状态..."

    # 检查certbot timer
    if systemctl is-enabled certbot.timer >/dev/null 2>&1; then
        if systemctl is-active certbot.timer >/dev/null 2>&1; then
            log_success "certbot.timer 服务运行正常"
            write_log "AUTO_RENEWAL: certbot.timer 服务正常"
        else
            log_warning "certbot.timer 服务未运行"
            write_log "AUTO_RENEWAL_WARNING: certbot.timer 服务未运行"
        fi
    else
        log_warning "certbot.timer 服务未启用"
        write_log "AUTO_RENEWAL_WARNING: certbot.timer 服务未启用"
    fi

    # 测试续期
    log_info "测试证书续期..."
    if certbot renew --dry-run >/dev/null 2>&1; then
        log_success "证书续期测试通过"
        write_log "RENEWAL_TEST: 测试通过"
    else
        log_error "证书续期测试失败"
        write_log "RENEWAL_TEST: 测试失败"
    fi
}

# 生成监控报告
generate_report() {
    local total_certs=$1
    local expired_certs=$2
    local expiring_certs=$3
    local ok_certs=$4

    echo ""
    echo "========================================"
    echo "SSL证书监控报告"
    echo "========================================"
    echo "检查时间: $(date)"
    echo "总证书数: $total_certs"
    echo "已过期: $expired_certs"
    echo "即将过期: $expiring_certs"
    echo "状态正常: $ok_certs"
    echo "========================================"

    write_log "REPORT: 总计=$total_certs, 过期=$expired_certs, 即将过期=$expiring_certs, 正常=$ok_certs"
}

# 发送告警通知 (可扩展)
send_alert() {
    local message=$1

    # 这里可以集成邮件、钉钉、企业微信等通知方式
    echo "ALERT: $message"
    write_log "ALERT: $message"

    # 示例：发送到系统日志
    logger -t "ssl_monitor" "$message"
}

# 主函数
main() {
    echo "========================================"
    echo "SSL证书监控脚本"
    echo "Let's Encrypt 证书状态检查"
    echo "========================================"

    # 创建日志文件目录
    mkdir -p "$(dirname "$LOG_FILE")"

    write_log "开始SSL证书监控检查"

    # 检查证书目录是否存在
    if [ ! -d "$CERT_DIR" ]; then
        log_error "证书目录不存在：$CERT_DIR"
        write_log "ERROR: 证书目录不存在 - $CERT_DIR"
        exit 1
    fi

    # 统计变量
    local total_certs=0
    local expired_certs=0
    local expiring_certs=0
    local ok_certs=0
    local https_errors=0

    # 遍历所有证书
    for domain_dir in "$CERT_DIR"/*; do
        if [ -d "$domain_dir" ]; then
            local domain=$(basename "$domain_dir")

            # 跳过README文件
            if [ "$domain" = "README" ]; then
                continue
            fi

            total_certs=$((total_certs + 1))

            # 检查证书状态
            check_certificate "$domain_dir"
            local cert_status=$?

            case $cert_status in
            0)
                ok_certs=$((ok_certs + 1))
                ;;
            1)
                expiring_certs=$((expiring_certs + 1))
                ;;
            2)
                expired_certs=$((expired_certs + 1))
                send_alert "证书已过期: $domain"
                ;;
            esac

            # 测试HTTPS访问
            echo "测试HTTPS访问..."
            if ! test_https_access "$domain"; then
                https_errors=$((https_errors + 1))
                send_alert "HTTPS访问失败: $domain"
            fi

            echo ""
        fi
    done

    # 检查自动续期
    check_auto_renewal

    # 生成报告
    generate_report "$total_certs" "$expired_certs" "$expiring_certs" "$ok_certs"

    # 发送汇总告警
    if [ $expired_certs -gt 0 ]; then
        send_alert "发现 $expired_certs 个过期证书"
    fi

    if [ $expiring_certs -gt 0 ]; then
        send_alert "发现 $expiring_certs 个即将过期证书"
    fi

    if [ $https_errors -gt 0 ]; then
        send_alert "发现 $https_errors 个HTTPS访问错误"
    fi

    write_log "SSL证书监控检查完成"

    # 返回适当的退出码
    if [ $expired_certs -gt 0 ]; then
        exit 2 # 有过期证书
    elif [ $expiring_certs -gt 0 ] || [ $https_errors -gt 0 ]; then
        exit 1 # 有警告
    else
        exit 0 # 一切正常
    fi
}

# 如果是定时任务调用，可以添加静默模式
if [ "$1" = "--quiet" ]; then
    # 静默模式：只记录日志，不输出到终端
    main >/dev/null
else
    # 正常模式：输出到终端
    main
fi
