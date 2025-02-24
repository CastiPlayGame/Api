import psutil
import json
import sys
import argparse
import time
intervalCapture = 0.5

def get_cpu_info(cores=False, core=False, nc=False, nt=False):
    cpu_info = {}

    if core:
        cpu_info['cpu_total_percentage'] = psutil.cpu_percent(interval=intervalCapture) / psutil.cpu_count()

    if cores:
        cpu_per_core_percentages = psutil.cpu_percent(interval=intervalCapture, percpu=True)
        cpu_count = psutil.cpu_count()
        adjusted_cpu_per_core_percentages = [percentage / cpu_count for percentage in cpu_per_core_percentages]
        cpu_info['cpu_per_core_percentage'] = adjusted_cpu_per_core_percentages
    if nc:
        cpu_info['num_cores'] = psutil.cpu_count(logical=False)
    
    if nt:
        cpu_info['num_threads'] = psutil.cpu_count(logical=True)
    
    return cpu_info

def get_ram_info(use=False, total=False, used=False, free=False):
    memory = psutil.virtual_memory()
    ram_info = {}
    
    if use:
        ram_info['ram_percentage'] = memory.percent
    
    if total:
        ram_info['ram_total'] = memory.total
    
    if used:
        ram_info['ram_used'] = memory.used
    
    if free:
        ram_info['ram_free'] = memory.available
    
    return ram_info

def get_disk_info(use=False, usedisks=False, total=False, used=False, free=False, totaldisks=False, freedisks=False):
    disk_info = {}
    
    if use:
        disk_io_before = psutil.disk_io_counters()
        time.sleep(intervalCapture)
        disk_io_after = psutil.disk_io_counters()
        read_bytes_diff = disk_io_after.read_bytes - disk_io_before.read_bytes
        write_bytes_diff = disk_io_after.write_bytes - disk_io_before.write_bytes
        total_bytes_diff = read_bytes_diff + write_bytes_diff
        max_disk_throughput = 100 * 1024 * 1024  # Ejemplo: 100 MB/s
        disk_usage = (total_bytes_diff / max_disk_throughput) * 100
        disk_info['disk_percentage'] = disk_usage
    
    if total:
        disk_info['disk_total'] = psutil.disk_usage('/').total
    
    if used:
        disk_info['disk_used'] = psutil.disk_usage('/').used
    
    if free:
        disk_info['disk_free'] = psutil.disk_usage('/').free
    
    if usedisks:
        disk_info['all_disks_percentage'] = [psutil.disk_usage(part.mountpoint).used for part in psutil.disk_partitions()]
    
    if totaldisks:
        disk_info['all_disks_total'] = [psutil.disk_usage(part.mountpoint).total for part in psutil.disk_partitions()]
    
    if freedisks:
        disk_info['all_disks_free'] = [psutil.disk_usage(part.mountpoint).free for part in psutil.disk_partitions()]
    
    return disk_info

def get_bandwidth_usage():
    net_io_before = psutil.net_io_counters()
    time.sleep(intervalCapture)
    net_io_after = psutil.net_io_counters()

    bytes_sent_diff = net_io_after.bytes_sent - net_io_before.bytes_sent
    bytes_recv_diff = net_io_after.bytes_recv - net_io_before.bytes_recv
    total_network_usage_bits = (bytes_sent_diff + bytes_recv_diff) * 8

    max_network_bandwidth = 100 * 1024 * 1024  # Example: 100 Mbps
    bandwidth_usage = (total_network_usage_bits / max_network_bandwidth) * 100

    return {'bandwidth_usage': int(bandwidth_usage)}

def parse_arguments():
    parser = argparse.ArgumentParser(description="System Resource Monitor")
    parser.add_argument('--cpu', nargs='+', choices=['cores', 'core', 'nc', 'nt'], help="CPU options")
    parser.add_argument('--ram', nargs='+', choices=['use', 'total', 'used', 'free'], help="RAM options")
    parser.add_argument('--disk', nargs='+', choices=['use', 'usedisks', 'total', 'used', 'free', 'totaldisks', 'freedisks'], help="Disk options")
    parser.add_argument('--bandwidth', action='store_true', help="Monitor bandwidth usage")
    return parser.parse_args()


def format_numbers(system_info):
    formatted_info = {}
    for key, value in system_info.items():
        if isinstance(value, float) and value != int(value):
            formatted_info[key] = round(value, 1)
        elif isinstance(value, dict):
            formatted_info[key] = format_numbers(value)
        else:
            formatted_info[key] = value
    return formatted_info
    
def main():
    args = parse_arguments()
    system_info = {}

    if args.cpu:
        cpu_info = get_cpu_info(
            cores='cores' in args.cpu,
            core='core' in args.cpu,
            nc='nc' in args.cpu,
            nt='nt' in args.cpu
        )
        system_info.update(cpu_info)

    if args.ram:
        ram_info = get_ram_info(
            use='use' in args.ram,
            total='total' in args.ram,
            used='used' in args.ram,
            free='free' in args.ram
        )
        system_info.update(ram_info)

    if args.disk:
        disk_info = get_disk_info(
            use='use' in args.disk,
            usedisks='usedisks' in args.disk,
            total='total' in args.disk,
            used='used' in args.disk,
            free='free' in args.disk,
            totaldisks='totaldisks' in args.disk,
            freedisks='freedisks' in args.disk
        )
        system_info.update(disk_info)

    if args.bandwidth:
        bandwidth_info = get_bandwidth_usage()
        system_info.update(bandwidth_info)

    formatted_info = format_numbers(system_info)

    json.dump(formatted_info, sys.stdout)
    sys.stdout.write("\n")

if __name__ == "__main__":
    main()