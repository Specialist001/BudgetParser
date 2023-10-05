<?php

namespace App\Console\Commands;

use Google_Client;
use Google_Service_Sheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ParseBudgetData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parse:budget-data';
    protected $description = 'Parse and save budget data from the table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $budgets = [];
        $months = [];

        try {
            $client = new Google_Client();
            $client->setAuthConfig(storage_path(config('sheets.service.file')));

            $client->addScope(Google_Service_Sheets::SPREADSHEETS_READONLY);

            $sheets = new Google_Service_Sheets($client);
            $spreadsheet_id = config('sheets.post_spreadsheet_id');
            $sheet_name = 'MA';

            $response = $sheets->spreadsheets_values->get($spreadsheet_id, $sheet_name);
            $values = $response->getValues();
            // remove first ros title
            array_shift($values);
            $category = null;

            // make sorted array
            foreach ($values as $item) {
                if (!empty($item)) {
                    if (!empty($item[1]) && $item[1] == 'January') {
                        // make months array
                        $months = array_slice($item, 1, 12);
                        // change keys to month numbers
                        $months = array_combine(range(1, 12), $months);
                    }

                    if (empty($item[0]) || $item[0] == 'Total') {
                        continue;
                    }
                    if ($item[0] == 'CO-OP') {
                        break;
                    }
                    if (!isset($item[1])) {
                        // this is category
                        $category = $item[0];
                        continue;
                    }

                    if ($category) {
                        $budgets[$category]['category_name'] = $category;
                        $budgets[$category]['category_products'][] = [
                            'product_name' => $item[0],
                            'prices_by_month' => array_combine($months, array_slice($item, 1, 12))
                        ];
                    }
                }
            }

            $budgets = array_values($budgets);

            $inserted_items = 0;
            $updated_items = 0;

            foreach ($budgets as $budget) {
                $category = DB::table('categories')->where('name', $budget['category_name'])->first();
                if (!$category) {
                    $category_id = DB::table('categories')->insertGetId([
                        'name' => $budget['category_name'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $category_id = $category->id;
                }

                $category_products = $budget['category_products'];
                foreach ($category_products as $category_product) {
                    // check product by name from `products` table, if not exists, create
                    $product = DB::table('products')->where('name', $category_product['product_name'])->first();
                    if (!$product) {
                        $product_id = DB::table('products')->insertGetId([
                            'name' => $category_product['product_name'],
                            'category_id' => $category_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $product_id = $product->id;
                    }
                    foreach ($category_product['prices_by_month'] as $month => $price) {
                        // remove $ sign from price
                        $price = str_replace('$', '', $price);
                        // remove comma from price
                        $price = (float)str_replace(',', '', $price);
                        // if price is empty or 0, skip
                        if (!$price || $price == 0.00) {
                            continue;
                        }

                        $budget_condition = [
                            'category_id' => $category_id,
                            'product_id' => $product_id,
                            'year' => (int)date('Y'),
                            'month' => idate('m', strtotime($month)),
                        ];

                        $__budget = DB::table('budgets')
                            ->where($budget_condition)
                            ->first();

                        if ($__budget) {
                            // if price is same, skip
                            if ($__budget->amount == $price) {
                                continue;
                            }
                            // update price
                            DB::table('budgets')
                                ->where($budget_condition)
                                ->update(['amount' => $price, 'updated_at' => now()]);
                            $updated_items++;
                        } else {
                            // insert
                            DB::table('budgets')
                                ->insert([
                                    'category_id' => $category_id,
                                    'product_id' => $product_id,
                                    'year' => (int)date('Y'),
                                    'month' => idate('m', strtotime($month)),
                                    'amount' => $price,
                                    'month_name' => $month,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]);
                            $inserted_items++;
                        }
                    }
                }
            }

            echo "Inserted items: $inserted_items\n";
            echo "Updated items: $updated_items\n";
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }
}
