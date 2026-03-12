<?php
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=product_import_template_2026.csv');

// Open output stream
$output = fopen('php://output', 'w');

// Updated column headers - 2026 recommended format
fputcsv($output, [
    'Product Name',                  // Required
    'Product Code',                  // Optional - unique code
    'Category',                      // Required - must match existing category name
    'Subcategory',                   // Optional - must match existing subcategory under selected category
    'Current Stock',                 // Optional - initial stock quantity for selected shop
    'Barcode',                       // Optional
    'HSN Code',                      // Optional - must exist in your gst_rates table
    'Unit of Measure',               // Required - e.g. pcs, coil, mtr, kg, nos, etc.
    
    // === Modern/Preferred Pricing Columns ===
    'MRP',                           // Recommended - Maximum Retail Price
    'Discount',                      // e.g. 28% or 50 (for fixed amount)
    
    'Retail Markup Type',            // percentage / fixed / percent / amount / rupees / ₹
    'Retail Markup',                 // e.g. 35 or 35% or 40
    
    'Wholesale Markup Type',         // percentage / fixed / percent / amount / rupees / ₹
    'Wholesale Markup',              // e.g. 22 or 22% or 25
    
       // === Referral Columns ===
    'Referral Enabled',              // Yes / No / 1 / 0
    'Referral Type',                 // percentage / fixed
    'Referral Value'                 // e.g. 5.00 or 3.5
]);

// ─────────────────────────────────────────────────────────────────────────────
// Example rows with different usage patterns
// ─────────────────────────────────────────────────────────────────────────────

// Example 1: Modern way → MRP + Discount + Markup percentages
fputcsv($output, [
    '6 inch PVC Pipe SCH 80',
    'PIPE-006-GR',
    'Pipes',
    'Finolex',
    '100',
    '8901234567890',
    '3917',
    'pcs',
    '850.00',           // MRP
    '28%',              // Discount → will calculate Stock Price ≈ 612
    'percentage',       // Retail Markup Type
    '35',               // → Retail ≈ 826.20
    'percentage',       // Wholesale Markup Type
    '22',               // → Wholesale ≈ 746.64
    
    'Yes',
    'percentage',
    '4.50'
]);



fclose($output);
exit();