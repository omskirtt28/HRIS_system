<?php
declare(strict_types=1);

/** Directory display groups approved from MEET OUR TEAM.jpg; stored department IDs stay intact. */
final class EmployeeDirectoryHierarchy
{
    public static function groups(): array
    {
        return [
            'FINANCE'=>['name'=>'Finance','icon'=>'chart','detail'=>'Accounting · Billing & Collection · Cash Management · Audit'],
            'INVENTORY'=>['name'=>'Inventory Management','icon'=>'boxes','detail'=>'Production · Stock Management'],
            'MANPOWER'=>['name'=>'Manpower Planning','icon'=>'users','detail'=>'Administration · Information System / MIS · PMMD'],
            'MARKETING'=>['name'=>'Marketing & Creatives','icon'=>'megaphone','detail'=>'Marketing · Digital Marketing · VM & Creatives'],
        ];
    }

    private static function matchingKey(string $label): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($label)) ?? '';
    }

    private static function teams(): array
    {
        return [
            'FINANCE'=>['FINANCE','Finance','chart'],
            'ACCOUNTING'=>['FINANCE','Accounting','book'],
            'BILLINGCOLLECTION'=>['FINANCE','Billing & Collection','receipt'],
            'BILLINGANDCOLLECTION'=>['FINANCE','Billing & Collection','receipt'],
            'CASHMANAGEMENT'=>['FINANCE','Cash Management','wallet'],
            'TREASURY'=>['FINANCE','Cash Management','wallet'],
            'AUDIT'=>['FINANCE','Audit','shield'],
            'INVENTORYMANAGEMENT'=>['INVENTORY','Inventory Management','boxes'],
            'PRODUCTION'=>['INVENTORY','Production','settings'],
            'STOCKMANAGEMENT'=>['INVENTORY','Stock Management','boxes'],
            'STOCKCONTROL'=>['INVENTORY','Stock Management','boxes'],
            'MANPOWERPLANNING'=>['MANPOWER','Manpower Planning','users'],
            'ADMINISTRATION'=>['MANPOWER','Administration','clipboard'],
            'ADMIN'=>['MANPOWER','Administration','clipboard'],
            'INFORMATIONSYSTEM'=>['MANPOWER','Information System / MIS','monitor'],
            'INFORMATIONSYSTEMS'=>['MANPOWER','Information System / MIS','monitor'],
            'MIS'=>['MANPOWER','Information System / MIS','monitor'],
            'PMMD'=>['MANPOWER','PMMD','briefcase'],
            'MARKETING'=>['MARKETING','Marketing','megaphone'],
            'MARKETINGCREATIVES'=>['MARKETING','Marketing & Creatives','megaphone'],
            'MARKETINGANDCREATIVES'=>['MARKETING','Marketing & Creatives','megaphone'],
            'DIGITAL'=>['MARKETING','Digital Marketing','monitor'],
            'DIGITALMARKETING'=>['MARKETING','Digital Marketing','monitor'],
            'DIGITALMKTG'=>['MARKETING','Digital Marketing','monitor'],
            'VMCREATIVES'=>['MARKETING','VM & Creatives','palette'],
            'VMCREATIVE'=>['MARKETING','VM & Creatives','palette'],
            'VMANDCREATIVES'=>['MARKETING','VM & Creatives','palette'],
            'VMANDCREATIVE'=>['MARKETING','VM & Creatives','palette'],
            'CREATIVE'=>['MARKETING','VM & Creatives','palette'],
            'CREATIVES'=>['MARKETING','VM & Creatives','palette'],
        ];
    }

    public static function department(string $label): array
    {
        $match=self::teams()[self::matchingKey($label)] ?? null;
        return $match ? ['main'=>$match[0],'name'=>$match[1],'icon'=>$match[2]] : ['main'=>'','name'=>$label,'icon'=>'briefcase'];
    }

    public static function icon(string $label): string
    {
        $known=self::department($label);
        if($known['main']!=='') return $known['icon'];
        $key=self::matchingKey($label);
        return match($key) {
            'DIGITAL','ONLINESHOP'=>'monitor',
            'VMCREATIVES'=>'palette',
            'SUPPLYDEMAND','SUPPLYANDDEMAND'=>'boxes',
            'CSR','CORPORATESOCIALRESPONSIBILITY'=>'heart',
            'RETAIL','RETAILDEVELOPMENT'=>'store',
            default=>'briefcase',
        };
    }
}
