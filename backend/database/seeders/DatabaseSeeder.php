<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create a Default Admin User
        User::factory()->create([
            'name' => 'Demo Administrator',
            'email' => 'admin@example.com',
        ]);

        // 2. Create Customers
        $customersData = [
            ['name' => 'John Doe', 'email' => 'john.doe@gmail.com', 'phone' => '555-0101', 'city' => 'New York'],
            ['name' => 'Jane Smith', 'email' => 'jane.smith@yahoo.com', 'phone' => '555-0102', 'city' => 'San Francisco'],
            ['name' => 'Alice Johnson', 'email' => 'alice.j@hotmail.com', 'phone' => '555-0103', 'city' => 'Chicago'],
            ['name' => 'Bob Brown', 'email' => 'bob.brown@gmail.com', 'phone' => '555-0104', 'city' => 'Austin'],
            ['name' => 'Charlie Davis', 'email' => 'charlie.d@outlook.com', 'phone' => '555-0105', 'city' => 'Seattle'],
            ['name' => 'Diana Evans', 'email' => 'diana.e@gmail.com', 'phone' => '555-0106', 'city' => 'Boston'],
            ['name' => 'Ethan Ford', 'email' => 'ethan.ford@yahoo.com', 'phone' => '555-0107', 'city' => 'Los Angeles'],
            ['name' => 'Fiona Green', 'email' => 'fiona.g@gmail.com', 'phone' => '555-0108', 'city' => 'Denver'],
            ['name' => 'George Harris', 'email' => 'george.h@gmail.com', 'phone' => '555-0109', 'city' => 'Miami'],
            ['name' => 'Hannah Martin', 'email' => 'hannah.m@yahoo.com', 'phone' => '555-0110', 'city' => 'Dallas'],
            ['name' => 'Ian Clark', 'email' => 'ian.c@gmail.com', 'phone' => '555-0111', 'city' => 'New York'],
            ['name' => 'Julia Lewis', 'email' => 'julia.l@gmail.com', 'phone' => '555-0112', 'city' => 'San Francisco'],
            ['name' => 'Kevin Wright', 'email' => 'kevin.w@yahoo.com', 'phone' => '555-0113', 'city' => 'Chicago'],
            ['name' => 'Laura Hill', 'email' => 'laura.h@gmail.com', 'phone' => '555-0114', 'city' => 'Austin'],
            ['name' => 'Michael Scott', 'email' => 'michael.s@dundermifflin.com', 'phone' => '555-0115', 'city' => 'Scranton'],
            ['name' => 'Pam Beesly', 'email' => 'pam.b@dundermifflin.com', 'phone' => '555-0116', 'city' => 'Scranton'],
            ['name' => 'Jim Halpert', 'email' => 'jim.h@dundermifflin.com', 'phone' => '555-0117', 'city' => 'Scranton'],
            ['name' => 'Dwight Schrute', 'email' => 'dwight.s@schrutefarms.com', 'phone' => '555-0118', 'city' => 'Scranton'],
            ['name' => 'Angela Martin', 'email' => 'angela.m@gmail.com', 'phone' => '555-0119', 'city' => 'Atlanta'],
            ['name' => 'Oscar Martinez', 'email' => 'oscar.m@yahoo.com', 'phone' => '555-0120', 'city' => 'Atlanta']
        ];

        $customers = [];
        foreach ($customersData as $data) {
            $customers[] = Customer::create($data);
        }

        // 3. Create Products
        $productsData = [
            ['name' => 'Enterprise Laptop', 'category' => 'Electronics', 'price' => 1200.00, 'stock' => 50],
            ['name' => 'Pro Smartphone', 'category' => 'Electronics', 'price' => 800.00, 'stock' => 100],
            ['name' => 'Noise Cancelling Headphones', 'category' => 'Electronics', 'price' => 150.00, 'stock' => 75],
            ['name' => 'Ergonomic Office Chair', 'category' => 'Office Supplies', 'price' => 350.00, 'stock' => 30],
            ['name' => 'Electric Standing Desk', 'category' => 'Office Supplies', 'price' => 450.00, 'stock' => 20],
            ['name' => 'Leather Planner/Notebook', 'category' => 'Office Supplies', 'price' => 25.00, 'stock' => 150],
            ['name' => 'Smart Coffee Mug', 'category' => 'Home', 'price' => 80.00, 'stock' => 80],
            ['name' => 'Thermal Water Bottle', 'category' => 'Home', 'price' => 30.00, 'stock' => 200],
            ['name' => 'Cotton Brand Hoodie', 'category' => 'Apparel', 'price' => 45.00, 'stock' => 120],
            ['name' => 'Breathable Running Shoes', 'category' => 'Apparel', 'price' => 95.00, 'stock' => 60],
            ['name' => 'Mechanical Keyboard', 'category' => 'Electronics', 'price' => 120.00, 'stock' => 90],
            ['name' => 'LED Monitor 27"', 'category' => 'Electronics', 'price' => 250.00, 'stock' => 40],
            ['name' => 'Desk Mat', 'category' => 'Office Supplies', 'price' => 15.00, 'stock' => 100],
            ['name' => 'Ceramic Flower Vase', 'category' => 'Home', 'price' => 35.00, 'stock' => 50]
        ];

        $products = [];
        foreach ($productsData as $data) {
            $products[] = Product::create($data);
        }

        // 4. Create Orders & OrderItems spread across 2026
        // Let's create about 60 orders
        $totalOrders = 60;
        $startDate = Carbon::create(2026, 1, 1);
        
        for ($i = 0; $i < $totalOrders; $i++) {
            // Random customer
            $customer = $customers[array_rand($customers)];
            
            // Random date distributed over 2026 up to today (Aug 2026)
            $daysToAdd = rand(0, 230); // ~7.5 months of dates
            $orderDate = (clone $startDate)->addDays($daysToAdd);
            
            $order = Order::create([
                'customer_id' => $customer->id,
                'order_date' => $orderDate->toDateString(),
                'total_amount' => 0.00 // Will update after adding items
            ]);

            // Add 1 to 4 order items
            $itemCount = rand(1, 4);
            $selectedProducts = array_rand($products, $itemCount);
            
            // If selecting 1 item, array_rand returns a single index, convert it to an array
            if (!is_array($selectedProducts)) {
                $selectedProducts = [$selectedProducts];
            }

            $orderTotal = 0.00;
            foreach ($selectedProducts as $prodIndex) {
                $product = $products[$prodIndex];
                $quantity = rand(1, 3);
                $unitPrice = $product->price;
                $totalPrice = $unitPrice * $quantity;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice
                ]);

                $orderTotal += $totalPrice;
                
                // Reduce stock
                $product->stock = max(0, $product->stock - $quantity);
                $product->save();
            }

            // Update order amount
            $order->total_amount = $orderTotal;
            $order->save();
        }
    }
}
