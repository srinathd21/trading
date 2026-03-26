<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - Billing</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- SweetAlert2 CSS and JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            padding: 0px;
            margin: 0px;
            box-sizing: border-box;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
        }

        body {
            padding: 1px;
            background-color: #f5f7fa;
            padding-bottom: 70px; /* Space for fixed buttons */
        }

        .main-border {
            min-height: 100vh;
            width: 100%;
            border: 1px solid rgb(228, 228, 228);
            padding: 0px 2px 0px;
        }
        .main-border > div {
            width: 100%;
            border: 1px solid rgb(223, 223, 223);
            margin-bottom: 1px;
        }

        .center-section {
            min-height: 50vh;
        }

        .bottom-section {
            min-height: 28vh;
            padding: 10px;
        }
        .top-section {
            display: flex;
            flex-wrap: wrap;
        }

        .left-container {
            padding: 5px;
            width: 100%;
        }
        .right-container {
            padding: 5px;
            width: 100%;
        }
        
        @media (min-width: 992px) {
            .left-container {
                width: 80vw;
            }
            .right-container {
                width: 20vw;
            }
            .top-section {
                flex-wrap: nowrap;
            }
        }
        
        .left-container > div {
            display: flex;
            flex-wrap: wrap;
        }
        .invoice-section,
        .customer-section {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
        }
        .invoice-section,
        .customer-section > div > label,
        input,
        select {
            display: block;
        }
        .invoice-section > div,
        .customer-section > div {
            flex: 1;
            min-width: 18vw;
        }

        @media (max-width: 768px) {
            .invoice-section > div,
            .customer-section > div {
                min-width: 40vw;
            }
        }
        
        @media (max-width: 576px) {
            .invoice-section > div,
            .customer-section > div {
                min-width: 100%;
            }
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #2c3e50;
        }

        input,
        select {
            width: 100%;
            padding: 4px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            font-size: 14px;
            background-color: white;
            transition: all 0.2s;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        input[type="date"] {
            padding: 9px 12px;
        }
        
        /* Toast notifications */
        #toastContainer {
            position: fixed;
            bottom: 70px;
            right: 10px;
            z-index: 9999;
        }
        
        .custom-toast {
            min-width: 300px;
            font-size: 0.8rem;
            animation: slideInRight 0.3s ease-out;
            margin-bottom: 5px;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .loyalty-point {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #f8fafc;
            padding: 3px 15px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-top: auto;
        }

        .loyalty-point span {
            font-weight: 700;
            color: #1e40af;
            font-size: 16px;
        }

        .loyalty-point button {
            background-color: #8b5cf6;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .loyalty-point button:hover {
            background-color: #7c3aed;
        }

        .loyalty-point button:disabled {
            background-color: #9ca3af;
            cursor: not-allowed;
        }

        .right-container > div:not(.action-buttons):not(.loyalty-point) {
            margin-top: 10px;
        }
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 10px;
        }
        .action-buttons > button {
            border: none;
            padding: 4px 6px;
            border-radius: 2px;
            font-size: 10px;
            flex: 1;
            min-width: 60px;
            cursor: pointer;
        }
        .action-buttons button:nth-child(1) {
            background-color: #8b5cf6 ;
            color: white;
        }

        .action-buttons button:nth-child(2) {
            background-color: #10b981;
            color: white;
        }

        .action-buttons button:nth-child(3) {
            background-color: #3b82f6;
            color: white;
        }
        
        .action-buttons button:nth-child(4) {
            background-color: #f59e0b;
            color: white;
        }
        
       

        .action-buttons button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .action-buttons button:active {
            transform: translateY(0);
        }
        
        .action-buttons button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .product-select-section{
            padding: 5px;
            width: 100%;
        }
        .center-section{
            padding: 5px;
        }
      
        
        
        #search-product{
            width: 100%;
        }
        #qty{
          display: flex;

        }
        #qty>input{
          width: 70px;
          border-radius: 3px 0px 0px 3px ;
        }
        #qty>span{
          background-color: rgb(212, 212, 212);
          color: rgb(0, 0, 0);
          display: inline-block;
          font-size: 12px;
          border: 0px 10px 3px 0px !important;
          padding: 5px 4px 0px;
        }
        #unit-convert{
            background-color: #409faf;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 3px 15px;
            width: 100%;
            cursor: pointer;
        }
        
        #unit-convert:disabled {
            background-color: #9ca3af;
            cursor: not-allowed;
        }
        
        .product-add-button{
          padding-top: 25px;
        }
        #product-add-button{
          background-color: #109b2e;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 3px 15px;   
            width: 100%;
            cursor: pointer;
        }
        
        #product-add-button:disabled {
            background-color: #9ca3af;
            cursor: not-allowed;
        }
        
        #batch, #discount, #mrp, #selling-price{
          width: 100%;
        }
        .products-section{
          padding: 3px;
        }
        .cart-table-container{
          max-height: 32vh;
          width: 100%;
          overflow-x: auto;
          overflow-y: scroll;
        }
        .cart-table{
          width: 100%;
          min-width: 1200px;
        }
        .table-head{
          background-color: rgb(229, 229, 229);
          font-size: 12px;
          position: sticky;
          top: 0px;
        }
        .table-data{
          font-size: 12px;
        }
        th, td{
          border: 1px solid rgb(225, 225, 225) !important;
          text-align: center;
          display: table-cell;
        }
        
        @media (max-width: 1200px) {
            th, td {
                display: table-cell !important;
            }
        }
        
        th{
          padding: 0px 10px;
          
        }
        td{
          height: 30px;
        }
        .cart-table td>input{
          border: none;
          width: 100%;
          border-radius: 0px;
          padding: 0px 10px;
          height: 100%;
        }
        td>select{
          border: none;
          height: 100%;
          border-radius: 0px;
        }
        .colm-1{
        width: 2vw;
        padding: 5px;
        }
        .colm-2{
        width: 28vw; 
        }
        .colm-3{
        width: 7vw;  
        }
        .colm-4{
        width: 7vw;  
        }
        .colm-5{
        width: 10vw;  
        }

        .colm-6{
         width: 10vw; 
        }
        .colm-7{
        width: 5vw;  
        }
        .colm-8{
         width: 5vw; 
        }
        .colm-9{
        width: 10vw;  
        }
        .colm-10{
        width: 10vw;  
        }
        .trash-btn{
          margin-top: 5px;
          font-size: 10px;
          border: none;
          padding: 3px 8px 2px 10px;
          border-radius: 3px;
          background-color: red;
          color: white;
          cursor: pointer;
        }
        
        .price-type-badge {
            font-size: 0.6rem;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .retail-badge {
            background-color: #17a2b8;
            color: white;
        }
        
        .wholesale-badge {
            background-color: #28a745;
            color: white;
        }
        
        .additional-discount{
          display: flex;
          margin-bottom: 5px;
          width: 20vw;
        }
        
        /* Payment Methods Styling - UPDATED */
        .payment-details {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }

        .payment-method-checkbox {
            display: flex;
            align-items: center;
            width: auto;
            min-width: 70px;
        }

        .payment-method-checkbox input[type='checkbox'] {
            width: 15px !important;
            height: 15px;
            margin-right: 5px;
            margin-top: -2px;
            cursor: pointer;
        }

        .payment-method-checkbox label {
            display: inline-block;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            margin-bottom: 0;
        }

        /* Side-by-side payment inputs */
        .payment-inputs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .payment-input-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            display: none; /* Hidden by default */
        }

        .payment-input-card.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .payment-input-card:hover {
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .payment-input-card h6 {
            margin-bottom: 10px;
            color: #2d3748;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .payment-input-card h6 i {
            color: #4f46e5;
        }

        .payment-input-card input[type="number"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .payment-input-card input[type="text"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12px;
        }

        .payment-input-card input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .bottom-right-section{
          display: flex;
          flex-wrap: wrap;
          gap: 24px;
          align-items: flex-start;
          background: #ffffff;
          padding: 1px 30px;    
        }
        
        @media (max-width: 768px) {
            .bottom-right-section {
                padding: 1px 10px;
                gap: 15px;
            }
        }

        /* LEFT INPUT SECTION */
        .bottom-right-section > div:first-child {
            flex: 1;
            min-width: 200px;
        }

        .bottom-right-section label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #444;
        }

        .bottom-right-section input {
            width: 100%;
            padding: 2px 12px;
            border: 1px solid #dcdcdc;
            font-size: 12px;
            margin-bottom: 2px;
        }

        .bottom-right-section input:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13,110,253,0.15);
        }
        .bs-container{
          border: 1px solid rgb(207, 207, 207);
          width: 100%;
          min-width: 250px;
        }
        .bs-container>tr, td{
          width: ;
          padding: 2px 5px;
        }

        .bs-container td:first-child {
            text-align: left;
            font-weight: 600;
        }

        .bs-container td:last-child {
            text-align: right;
            font-weight: 600;
        }

        /* First row (Sub Total) */
        .bs-container tr:first-child td {
            font-size: 16px;
        }

        /* Grand Total highlight */
        .bs-container tr:last-child td {
            font-size: 18px;
            font-weight: 700;
            color: #0d6efd;
        }
        
        /* Responsive adjustments for bottom section */
        @media (max-width: 992px) {
            .bottom-section {
                flex-direction: column;
            }
            
            .bottom-left-section, .bottom-right-section {
                width: 100%;
            }
            
            .bottom-right-section {
                margin-top: 15px;
            }
        }
        
        /* Responsive adjustments for the cart table on smaller screens */
        @media (max-width: 768px) {
            .cart-table-container {
                font-size: 11px;
            }
            
            .colm-1, .colm-3, .colm-4, .colm-7, .colm-8 {
                min-width: 40px;
            }
            
            .colm-2 {
                min-width: 150px;
            }
            
            .colm-5, .colm-6, .colm-9, .colm-10 {
                min-width: 70px;
            }
        }
        
        /* Utility classes for responsiveness */
        .d-flex {
            display: flex !important;
        }
        
        .justify-content-between {
            justify-content: space-between !important;
        }
        
        @media (max-width: 576px) {
            .d-flex {
                flex-direction: column;
            }
            
            .justify-content-between {
                justify-content: flex-start !important;
            }
            
            .bottom-right-section > div:first-child,
            .bs-container {
                width: 100%;
            }
        }
        
        /* Modal styles */
        .modal-sm .modal-content {
            font-size: 0.85rem;
        }
        
        .modal-sm .modal-header {
            padding: 0.5rem 1rem;
        }
        
        .modal-sm .modal-body {
            padding: 0.75rem;
        }
        
        .modal-sm .modal-footer {
            padding: 0.5rem;
        }
        
        /* Stock indicators */
        .stock-badge {
            font-size: 0.6rem;
            padding: 1px 4px;
            border-radius: 2px;
            margin-left: 3px;
        }
        
        .shop-stock {
            background: #17a2b8;
            color: white;
        }
        
        .warehouse-stock {
            background: #6c757d;
            color: white;
        }
        
        .low-stock {
            background: #dc3545;
            color: white;
        }
        
        .out-of-stock {
            background: #343a40;
            color: white;
        }
        
        /* Referral badge */
        .referral-badge {
            background: #6f42c1;
            color: white;
            font-size: 0.6rem;
            padding: 1px 4px;
            border-radius: 2px;
            margin-left: 3px;
        }
        
        /* Unit badges */
        .unit-badge {
            background: #6c757d;
            color: white;
            font-size: 0.6rem;
            padding: 1px 4px;
            border-radius: 2px;
            margin-left: 3px;
        }
        
        /* MRP badge */
        .mrp-badge {
            background: #dc3545;
            color: white;
            font-size: 0.6rem;
            padding: 1px 4px;
            border-radius: 2px;
            margin-left: 3px;
        }
        
        /* GST badge */
        .gst-badge {
            background: #6f42c1;
            color: white;
            font-size: 0.6rem;
            padding: 1px 4px;
            border-radius: 2px;
            margin-left: 3px;
        }
        
        /* Quantity input styling */
        .quantity-input-group {
            display: flex;
            align-items: center;
        }
        
        .quantity-btn {
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            padding: 2px 8px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .quantity-btn:first-child {
            border-radius: 3px 0 0 3px;
            border-right: none;
        }
        
        .quantity-btn:last-child {
            border-radius: 0 3px 3px 0;
            border-left: none;
        }
        
        .quantity-input {
            width: 50px;
            text-align: center;
            border: 1px solid #ced4da;
            border-left: none;
            border-right: none;
            padding: 2px;
        }
        
        /* Cart empty message */
        .cart-empty {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-style: italic;
        }
        
        /* Category info */
        .category-info {
            font-size: 0.6rem;
            color: #6c757d;
            font-style: italic;
        }
        
        /* Amount Distribution Styling */
        .amount-distribution {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border: 1px solid #dee2e6;
        }

        .amount-distribution h6 {
            margin-bottom: 15px;
            color: #495057;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .amount-distribution h6 i {
            color: #0d6efd;
        }

        .amount-distribution-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .amount-distribution-row:last-child {
            border-bottom: none;
        }

        .amount-distribution-row span:first-child {
            font-weight: 600;
        }

        .amount-distribution-row span:last-child {
            color: #28a745;
            font-weight: bold;
        }
        
        /* Fixed Bottom Action Buttons */
        .fixed-bottom-buttons {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 10px 15px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            border-top: 1px solid #dee2e6;
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .fixed-bottom-buttons .btn {
            flex: 1;
            max-width: 200px;
            padding: 10px 15px;
            font-size: 14px;
            font-weight: 600;
        }

        /* Payment Summary Highlight */
        .payment-summary-highlight {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            padding: 15px;
            border-radius: 8px;
            border: 2px solid #2196f3;
            margin: 15px 0;
        }
        
        .payment-summary-highlight h6 {
            color: #1565c0;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        /* Button Styling */
        .btn-action-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-action-group .btn {
            flex: 1;
            min-width: 120px;
            padding: 10px 15px;
            font-size: 13px;
            font-weight: 600;
        }

        /* Responsive adjustments for payment grid */
        @media (max-width: 768px) {
            .payment-inputs-grid {
                grid-template-columns: 1fr;
            }
            
            .payment-input-card {
                padding: 10px;
            }
            
            .fixed-bottom-buttons {
                padding: 8px 10px;
            }
            
            .fixed-bottom-buttons .btn {
                max-width: none;
                min-width: 0;
                padding: 8px 12px;
                font-size: 12px;
            }
        }

        /* Total Summary Box */
        .total-summary-box {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }

        .total-summary-box .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #dee2e6;
        }

        .total-summary-box .summary-row:last-child {
            border-bottom: none;
        }

        .total-summary-box .summary-label {
            font-weight: 600;
            color: #495057;
        }

        .total-summary-box .summary-value {
            font-weight: bold;
            color: #28a745;
        }

        .total-summary-box .grand-total {
            font-size: 18px;
            color: #0d6efd;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #0d6efd;
        }
        /* Add to your existing CSS */
.customer-credit-info {
    font-size: 11px;
    padding: 8px 12px;
    margin-top: 5px;
    border-radius: 4px;
    background-color: #e7f3ff;
    border: 1px solid #b3d7ff;
}

.customer-credit-info small {
    font-size: 10px;
}
/* Improved product selection section alignment */
.product-select-section {
    display: flex;
    justify-content: start;
    gap: 10px;
    align-items: end;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 15px;
    border: 1px solid #e9ecef;
}

.product-select-section > div {
    margin-bottom: 0;
}

.product-select-section label {
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 5px;
    color: #495057;
    display: block;
}

.product-select-section input,
.product-select-section select {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 12px;
    height: 32px;
}

#qty {
    display: flex;
    align-items: center;
    height: 32px;
}

#qty-input {
    width: 60px;
    border: 1px solid #ced4da;
    border-right: none;
    border-radius: 4px 0 0 4px;
    padding: 6px;
    text-align: center;
}

#qty-unit {
    background: #e9ecef;
    padding: 0 10px;
    border: 1px solid #ced4da;
    border-left: none;
    border-radius: 0 4px 4px 0;
    height: 32px;
    line-height: 32px;
    font-size: 11px;
    font-weight: 600;
}

.product-add-button {
    display: flex;
    align-items: end;
    height: 100%;
}

#product-add-button {
    height: 32px;
    width: 100%;
    padding: 6px;
    font-size: 12px;
    font-weight: 600;
}

#unit-convert {
    height: 32px;
    width: 100%;
    padding: 6px;
    font-size: 11px;
}

/* Fix cart table design */
.cart-table-container {
    max-height: 40vh;
    overflow: auto;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    background: white;
}

.cart-table {
    width: 100%;
    margin: 0;
    min-width: 1200px;
}

.cart-table th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    font-weight: 600;
    padding: 8px;
    font-size: 11px;
    z-index: 10;
}

.cart-table td {
    padding: 8px;
    vertical-align: middle;
    font-size: 11px;
}

.cart-table input,
.cart-table select {
    width: 100%;
    padding: 4px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    font-size: 11px;
    height: 28px;
}

/* Remove automatic discount calculation */
#discount {
    background: white !important;
}

/* Fix cart action buttons */
.cart-actions {
    display: flex;
    gap: 5px;
    justify-content: center;
}

.cart-actions button {
    width: 28px;
    height: 28px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
#btnClose{
    display: inline-block;
    text-decoration: none;
    position: fixed;
    right: 3px;
    top: 3px;
    z-index: 100;
}
#btnClose button{
    border: none;
    border-radius: 3px;
    padding: 5px 20px;
    background: red;
    color: white;
}.stock-badge {
    font-size: 0.75em;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 600;
    margin-left: 5px;
    white-space: nowrap;
}

.shop-stock {
    background-color: #28a745 !important;
    color: white !important;
}

.low-stock {
    background-color: #fd7e14 !important;
    color: white !important;
}

.out-of-stock {
    background-color: #dc3545 !important;
    color: white !important;
}

/* For Select2 dropdown items */
.select2-results__option .stock-badge {
    font-size: 0.7em;
    vertical-align: middle;
    display: inline-block;
}
.out-of-stock{
    color: red !important;
}
#required-star{
    color: red;
    font-size: 18px;
    position: absolute;
    margin-top: -5px;
}
/* Shipping button styling */
#btnShippingDetails {
    background-color: #17a2b8;
    color: white;
    border: none;
    transition: all 0.2s ease;
}

#btnShippingDetails:hover {
    background-color: #138496;
    transform: translateY(-1px);
}

#btnShippingDetails i {
    font-size: 10px;
}

/* Shipping details summary */
/* Replace the existing shipping-summary styles with this */
.shipping-summary {
    background-color: #f8f9fa;
    border-left: 3px solid #17a2b8;
    padding: 10px 15px;
    margin-top: 8px;
    border-radius: 6px;
    display: none;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}

.shipping-summary.show {
    display: flex;
}

.shipping-details-group {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    flex: 1;
}

.shipping-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: white;
    border-radius: 20px;
    border: 1px solid #e0e0e0;
    font-size: 11px;
    transition: all 0.2s ease;
}

.shipping-badge:hover {
    background: #f1f3f5;
    border-color: #17a2b8;
    transform: translateY(-1px);
}

.shipping-badge i {
    color: #17a2b8;
    font-size: 11px;
}

.shipping-badge .badge-value {
    font-weight: 600;
    color: #2c3e50;
}

.shipping-badge .badge-label {
    color: #6c757d;
    margin-right: 3px;
}

.shipping-charge-badge {
    background: #e8f5e9;
    border-color: #c8e6c9;
}

.shipping-charge-badge i {
    color: #2e7d32;
}

.shipping-charge-badge .badge-value {
    color: #2e7d32;
    font-weight: 700;
}

.shipping-actions {
    display: flex;
    gap: 5px;
    align-items: center;
}

.btn-clear-shipping {
    background: none;
    border: none;
    color: #6c757d;
    padding: 4px 10px;
    font-size: 11px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-clear-shipping:hover {
    background: #f8f9fa;
    color: #dc3545;
}

.btn-clear-shipping#btnEditShipping:hover {
    color: #17a2b8;
}

.btn-clear-shipping i {
    font-size: 10px;
}
/* Add this to your existing CSS styles */
.shipping-summary-horizontal {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 8px 12px;
    margin-top: 8px;
    display: none;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}

.shipping-summary-horizontal.show {
    display: flex;
}

.shipping-details-group {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: center;
    flex: 1;
}

.shipping-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: white;
    border-radius: 20px;
    border: 1px solid #e0e0e0;
    font-size: 11px;
    transition: all 0.2s ease;
}

.shipping-badge:hover {
    background: #f1f3f5;
    border-color: #17a2b8;
}

.shipping-badge i {
    color: #17a2b8;
    font-size: 11px;
}

.shipping-badge .badge-value {
    font-weight: 600;
    color: #2c3e50;
}

.shipping-badge .badge-label {
    color: #6c757d;
    margin-right: 3px;
}

.shipping-charge-badge {
    background: #e8f5e9;
    border-color: #c8e6c9;
}

.shipping-charge-badge i {
    color: #2e7d32;
}

.shipping-charge-badge .badge-value {
    color: #2e7d32;
    font-weight: 700;
}

.btn-clear-shipping {
    background: none;
    border: none;
    color: #dc3545;
    padding: 4px 8px;
    font-size: 11px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.btn-clear-shipping:hover {
    background: #fff5f5;
    color: #c82333;
}

.btn-clear-shipping i {
    font-size: 10px;
}
/* Shipping Details Horizontal Section */
.shipping-info-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 12px;
    margin: 10px 0;
    border: 1px solid #e9ecef;
}

.shipping-info-section h6 {
    margin-bottom: 10px;
    color: #495057;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
}

.shipping-details-horizontal {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    min-height: 40px;
}

.shipping-badge-horizontal {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    background: white;
    border-radius: 30px;
    border: 1px solid #e0e0e0;
    font-size: 12px;
    transition: all 0.2s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.shipping-badge-horizontal:hover {
    background: #f8f9fa;
    border-color: #17a2b8;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.shipping-badge-horizontal i {
    color: #17a2b8;
    font-size: 12px;
}

.shipping-badge-horizontal .badge-label {
    color: #6c757d;
    font-weight: 500;
    margin-right: 3px;
}

.shipping-badge-horizontal .badge-value {
    font-weight: 600;
    color: #2c3e50;
}

.shipping-charge-badge-horizontal {
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    border-color: #81c784;
}

.shipping-charge-badge-horizontal i {
    color: #2e7d32;
}

.shipping-charge-badge-horizontal .badge-value {
    color: #1b5e20;
    font-weight: 700;
}

.shipping-empty-state {
    color: #adb5bd;
    font-style: italic;
    display: flex;
    align-items: center;
    gap: 6px;
}

.shipping-empty-state i {
    color: #adb5bd;
}
    </style>
</head>

<body>
    <!-- Toast Container -->
    <div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999; margin-top: 70px;">
        <!-- Toasts will be added here dynamically -->
    </div>
      <a id="btnClose" href="invoices.php"><button><i class="fas fa-x"></i> Close</button></a>
    <div class="main-border">
        <div class="top-section">
            <div class="left-container">
                <div class="invoice-section">
                    <div>
                        <label for="invoice-type"><i class="fas fa-file-invoice"></i>
                            Invoice Type</label>
                        <select name="invoice-type" id="invoice-type" name="get-type">
                            <option value="gst">GST</option>
                            <option value="non-gst">NON GST</option>
                        </select>
                    </div>
                    <div>
                        <label for="invoice-number"><i class="fas fa-hashtag"></i>
                            Invoice Number</label>
                        <input type="text" name="invoice-number" id="invoice-number" readonly>
                    </div>
                    <div>
                        <label for="price-type"><i class="fas fa-tag"></i>
                            Price Type</label>
                        <select name="price-type" id="price-type" name="price-type">
                            <option value="retail">Retail</option>
                            <option value="wholesale">Wholesale</option>
                        </select>
                    </div>

                    <div>
                        <label for="date"><i class="fas fa-calendar-alt"></i>
                            Date</label>
                        <input type="date" id="date" name="date">
                    </div>
                </div>
                <br>
                <!-- Replace the customer-section div with this updated version -->
<div class="customer-section">
    <div>
        <label for="customer-name"><i class="fas fa-user"></i>
            Customer name <span id="required-star">*</span></label>
        <input type="text" id="customer-name" name="customer-name" value="Walk-in Customer" required>
    </div>
    <div>
        <label for="customer-contact"><i class="fas fa-phone"></i>
            Customer contact <span id="required-star">*</span></label>
        <select id="customer-contact" name="customer-contact" required>
            <option value="">-- Select phone --</option>
        </select>
    </div>
    <div>
        <label for="customer-address">
            <i class="fas fa-map-marker-alt"></i>
            Address
            <button type="button" id="btnShippingDetails" class="btn btn-sm btn-outline-info ms-2" style="padding: 0px 8px; font-size: 10px;">
                <i class="fas fa-truck"></i> Shipping
            </button>
        </label>
        <input type="text" id="customer-address" name="customer-address">
    </div>
    <div>
        <label for="customer-gstin"><i class="fas fa-id-card"></i>
            Gstin</label>
        <input type="text" name="customer-gstin" id="customer-gstin">
    </div>
</div>
            </div>
            <div class="right-container">
                
                <div>
                    <label for="referral"><i class="fas fa-user-friends"></i>
                        Referral</label>
                    <select id="referral" name="referral">
                        <option value="">-- No referral --</option>
                    </select>
                </div>
                
                
                <div class="action-buttons mt-2">
                    <button id="btnHoldList"><i class="fas fa-list me-1"></i> H L</button>
                    <button id="btnHold"><i class="fas fa-pause me-1"></i> H</button>
                    <button id="btnQuotationList"><i class="fas fa-list-check me-1"></i> Q L</button>
                    <button id="btnQuotation"><i class="fas fa-file-contract me-1"></i> Q</button>
                    <button id="btnClearCart" class="bg-danger text-white"><i class="fas fa-trash me-1"></i> Clear</button>
                  
                </div>
                <div class="loyalty-point">
                    <span id="customerPointsDisplay">0</span>
                    <button id="btnShowPointsDetails">Apply</button>
                </div>
            </div>
        </div>
        <div class="center-section">
            <h6>Add Product</h6>
            <div class="product-select-section">
                <div style="width:24vw;">
                    <label for="search-product">Search Product</label>
                    <select name="search-product" id="search-product">
                        <option value="">-- Search product --</option>
                    </select>
                </div>
                <div>
                    <label for="barcode">Barcode</label>
                    <div id="barcode">
                        <input type="text" id="barcode-input" name="barcode" placeholder="Scan barcode">
                    </div>
                </div>
                <div>
                    <label for="qty">Quantity</label>
                    <div id="qty">
                        <input type="number" id="qty-input" name="qty" min="0.1" step="0.1" value="1">
                        <span id="qty-unit">PCS</span>
                    </div>
                </div>
                <div>
                   
                    <button id="unit-convert" disabled><i class="fas fa-exchange-alt me-1"></i></button>
                </div>
                
                <div>
    <label for="discount">Discount</label>
    <div class="d-flex align-items-center gap-1">
        <input type="number" id="discount" name="discount" value="0" min="0" step="0.1" class="form-control" style="flex: 2;">
        <select id="discount-type" class="form-select" style="flex: 1;">
            <option value="percentage">%</option>
            <option value="fixed">₹</option>
        </select>
    </div>
</div>
                <div>
                    <label for="mrp">Mrp</label>
                    <input type="text" id="mrp" name="mrp" value="0" readonly>
                </div>
                <div>
                    <label for="selling-price">Selling Price</label>
                    <input type="text" id="selling-price" name="selling-price" value="0">
                </div>
                <div class="product-add-button">
                    <button id="product-add-button" disabled><i class="fas fa-plus me-1"></i> Add</button>
                </div>
            </div>
            <div class="products-section">
                <h6>Cart items</h6>
                <div class="cart-table-container" id="cartTableContainer">
    <table class="cart-table" id="cartTable">
        <thead>
            <tr class="table-head">
                <th class="colm-1">#</th>
                <th class="colm-2">Product</th>
                <th class="colm-3">Qty</th>
                <th class="colm-4">Unit</th>
                <th class="colm-5">Price type</th>
                <th class="colm-6">Dis</th>
                <th class="colm-7">Price</th>
                <th class="colm-8">Gst</th>
                <th class="colm-9">Total</th>
                <th class="colm-10">Action</th>
            </tr>
        </thead>
        <tbody id="cartBody">
            <!-- Cart items will be dynamically added here -->
            <tr id="emptyCartRow">
                <td colspan="10" class="cart-empty">No items in cart</td>
            </tr>
        </tbody>
    </table>
</div>
            </div>
        </div>
        <div class="bottom-section d-flex justify-content-between">
            <div class="bottom-left-section" style="width: 100%;">
 <div>
        <h6>Additional Discount</h6>
        <div class="additional-discount">
            <input type="number" name="additional-dis" id="additional-dis" value="0" min="0" step="0.01">
            <select name="overall-discount-type" id="overall-discount-type">
                <option value="rupees">₹</option>
                <option value="percentage">%</option>
            </select>
        </div>
    </div>
    
    <!-- NEW: Shipping Details Section - Place this here -->
    <div class="shipping-info-section" id="shippingInfoSection" style="margin-top: 15px;">
        <h6>
            <i class="fas fa-truck"></i> Shipping Details
            <button type="button" id="btnEditShippingFromDiscount" class="btn btn-sm btn-outline-info ms-2" style="padding: 2px 8px; font-size: 10px;">
                <i class="fas fa-edit"></i> Edit
            </button>
            <button type="button" id="btnClearShippingFromDiscount" class="btn btn-sm btn-outline-danger ms-1" style="padding: 2px 8px; font-size: 10px;">
                <i class="fas fa-trash"></i> Clear
            </button>
        </h6>
        <div id="shippingDetailsHorizontal" class="shipping-details-horizontal">
            <!-- Shipping details will appear here horizontally -->
            <div class="shipping-empty-state text-muted" style="font-size: 11px; padding: 8px;">
                <i class="fas fa-info-circle"></i> No shipping details added
            </div>
        </div>
    </div>
                <div>
                    <h6>Payment Methods</h6>
                    <div class="payment-details">
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="cash-checkbox" value="cash" checked>
                            <label for="cash-checkbox">Cash</label>
                        </div>
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="upi-checkbox" value="upi">
                            <label for="upi-checkbox">UPI</label>
                        </div>
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="bank-checkbox" value="bank">
                            <label for="bank-checkbox">Bank</label>
                        </div>
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="cheque-checkbox" value="cheque">
                            <label for="cheque-checkbox">Cheque</label>
                        </div>
                        <div class="payment-method-checkbox">
                            <input type="checkbox" name="payment-method" id="credit-checkbox" value="credit">
                            <label for="credit-checkbox">Credit</label>
                        </div>
                    </div>
                </div>
                
                <!-- Side-by-side Payment Inputs -->
                <div class="payment-inputs-grid" id="paymentInputsGrid">
                    <div class="payment-input-card active" id="cash-input-card">
                        <h6><i class="fas fa-money-bill-wave"></i> Cash Payment</h6>
                        <label for="cash-amount">Amount (₹)</label>
                        <input type="number" id="cash-amount" name="cash-amount" value="0" min="0" step="0.01" placeholder="Enter amount">
                    </div>
                    
                    <div class="payment-input-card" id="upi-input-card">
                        <h6><i class="fas fa-mobile-alt"></i> UPI Payment</h6>
                        <label for="upi-amount">Amount (₹)</label>
                        <input type="number" id="upi-amount" name="upi-amount" value="0" min="0" step="0.01" placeholder="Enter amount">
                        <label for="upi-reference" class="mt-2">Reference</label>
                        <input type="text" id="upi-reference" name="upi-reference" placeholder="UPI Transaction ID">
                    </div>
                    
                    <div class="payment-input-card" id="bank-input-card">
                        <h6><i class="fas fa-university"></i> Bank Transfer</h6>
                        <label for="bank-amount">Amount (₹)</label>
                        <input type="number" id="bank-amount" name="bank-amount" value="0" min="0" step="0.01" placeholder="Enter amount">
                        <label for="bank-reference" class="mt-2">Reference</label>
                        <input type="text" id="bank-reference" name="bank-reference" placeholder="Transaction Reference">
                    </div>
                    
                    <div class="payment-input-card" id="cheque-input-card">
                        <h6><i class="fas fa-money-check"></i> Cheque Payment</h6>
                        <label for="cheque-amount">Amount (₹)</label>
                        <input type="number" id="cheque-amount" name="cheque-amount" value="0" min="0" step="0.01" placeholder="Enter amount">
                        <label for="cheque-number" class="mt-2">Cheque Number</label>
                        <input type="text" id="cheque-number" name="cheque-number" placeholder="Cheque No.">
                    </div>
                    
                    <div class="payment-input-card" id="credit-input-card">
                        <h6><i class="fas fa-credit-card"></i> Credit Payment</h6>
                        <label for="credit-amount">Amount (₹)</label>
                        <input type="number" id="credit-amount" name="credit-amount" value="0" min="0" step="0.01" placeholder="Enter amount">
                        <label for="credit-reference" class="mt-2">Reference</label>
                        <input type="text" id="credit-reference" name="credit-reference" placeholder="Credit Note/Reference">
                    </div>
                </div>
                
                <!-- Payment Distribution will be inserted here -->
                <div id="paymentDistribution"></div>
            </div>
            <div class="bottom-right-section d-flex">
                <div>
                    <div class="total-summary-box">
                        <div class="summary-row">
                            <span class="summary-label">Sub Total:</span>
                            <span class="summary-value" id="subtotal-display">₹ 0.00</span>
                        </div>
                        <div class="summary-row" id="item-discount-row" style="display: none;">
                            <span class="summary-label">Item Discount:</span>
                            <span class="summary-value" id="item-discount-display">₹ 0.00</span>
                        </div>
                        <div class="summary-row" id="overall-discount-row">
                            <span class="summary-label">Overall Discount:</span>
                            <span class="summary-value" id="overall-discount-display">₹ 0.00</span>
                        </div>
                        <div class="summary-row" id="points-discount-row" style="display: none;">
                            <span class="summary-label">Points Discount:</span>
                            <span class="summary-value" id="points-discount-display">₹ 0.00</span>
                        </div>
                        <div class="summary-row" id="taxable-row" style="display: none;">
    <span class="summary-label">Taxable Value:</span>
    <span class="summary-value" id="taxable-display">₹ 0.00</span>
</div>
                        <div class="summary-row" id="cgst-row" style="display: none;">
                            <span class="summary-label">CGST:</span>
                            <span class="summary-value" id="cgst-display">₹ 0.00</span>
                        </div>
                        <div class="summary-row" id="sgst-row" style="display: none;">
                            <span class="summary-label">SGST:</span>
                            <span class="summary-value" id="sgst-display">₹ 0.00</span>
                        </div>
                        <div class="summary-row" id="igst-row" style="display: none;">
                            <span class="summary-label">IGST:</span>
                            <span class="summary-value" id="igst-display">₹ 0.00</span>
                        </div>
                        <div class="summary-row grand-total">
                            <span class="summary-label">Grand Total:</span>
                            <span class="summary-value" id="grand-total-display">₹ 0.00</span>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="mb-2">
                            <label for="total-paid">Total Paid</label>
                            <input type="text" id="total-paid" name="total-paid" readonly class="form-control">
                        </div>
                        <div class="mb-2">
                            <label for="change-given">Change Given</label>
                            <input type="text" id="change-given" name="change-given" readonly class="form-control">
                        </div>
                        <div>
                            <label for="pending-amount">Pending Amount</label>
                            <input type="text" id="pending-amount" name="pending-amount" readonly class="form-control">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Fixed Bottom Action Buttons -->
    <div class="fixed-bottom-buttons">
        <button id="btnAutoFillRemaining" class="btn btn-outline-primary">
            <i class="fas fa-magic me-1"></i> Auto-fill
        </button>
        <button id="btnGenerateBill" class="btn btn-primary">
            <i class="fas fa-file-invoice me-1"></i> Generate Bill
        </button>
        <button id="btnPrintBill" class="btn btn-success">
            <i class="fas fa-print me-1"></i> Print Bill
        </button>
    </div>
    
    <!-- Modals -->
    <!-- Hold Invoice Modal -->
    <div class="modal fade" id="holdInvoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Hold Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Reference Note</label>
                        <input type="text" id="holdReference" class="form-control" placeholder="Customer name or reason">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expires After</label>
                        <select id="holdExpiry" class="form-select">
                            <option value="24">24 hours</option>
                            <option value="48" selected>48 hours</option>
                            <option value="72">72 hours</option>
                            <option value="168">7 days</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmHold">Save Hold</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Hold List Modal -->
    <div class="modal fade" id="holdListModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Held Invoices</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Time</th>
                                    <th>Reference</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="holdListBody">
                                <!-- Hold list will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quotation Modal -->
    <div class="modal fade" id="quotationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Save Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Quotation #</label>
                        <input type="text" id="quotationNumber" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Valid Until</label>
                        <input type="date" id="quotationValidUntil" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea id="quotationNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveQuotationBtn">Save Quotation</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loyalty Points Modal -->
    <div class="modal fade" id="pointsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Loyalty Points</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <h6>Available Points: <span id="modalPointsValue" class="text-primary">0</span></h6>
                        <p class="mb-1">Total Earned: <span id="modalTotalEarned">0</span></p>
                        <p class="mb-3">Total Redeemed: <span id="modalTotalRedeemed">0</span></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Points to Redeem</label>
                        <div class="input-group">
                            <input type="number" id="pointsToRedeem" class="form-control" value="0" min="0">
                            <button class="btn btn-outline-primary" type="button" id="btnUseMaxPoints">Max</button>
                        </div>
                        <small class="text-muted">Each point = ₹<span id="redeemValuePerPoint">1.00</span> discount</small>
                    </div>
                    <div class="alert alert-info">
                        <small>Discount: ₹<span id="modalPointsDiscount">0.00</span></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="btnApplyPointsDiscount">Apply Discount</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmationTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmationMessage" class="mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmActionBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Shipping Details Modal -->
<div class="modal fade" id="shippingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-truck me-2"></i> Shipping Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="shippingForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="shipping-name" class="form-label">
                                <i class="fas fa-user me-1"></i> Receiver Name
                            </label>
                            <input type="text" class="form-control" id="shipping-name" 
                                   placeholder="Enter receiver name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="shipping-contact" class="form-label">
                                <i class="fas fa-phone me-1"></i> Contact Number
                            </label>
                            <input type="text" class="form-control" id="shipping-contact" 
                                   placeholder="Enter contact number">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="shipping-gstin" class="form-label">
                                <i class="fas fa-id-card me-1"></i> GSTIN (if any)
                            </label>
                            <input type="text" class="form-control" id="shipping-gstin" 
                                   placeholder="Enter GSTIN">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="shipping-vehicle" class="form-label">
                                <i class="fas fa-truck me-1"></i> Vehicle Number
                            </label>
                            <input type="text" class="form-control" id="shipping-vehicle" 
                                   placeholder="Enter vehicle number">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="shipping-address" class="form-label">
                            <i class="fas fa-map-marker-alt me-1"></i> Shipping Address
                        </label>
                        <textarea class="form-control" id="shipping-address" rows="3" 
                                  placeholder="Enter complete shipping address"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="shipping-charges" class="form-label">
                            <i class="fas fa-rupee-sign me-1"></i> Shipping Charges
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" class="form-control" id="shipping-charges" 
                                   value="0" min="0" step="0.01" placeholder="Enter shipping charges">
                        </div>
                        <small class="text-muted">Shipping charges will be added to the grand total</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-info" id="btnSaveShipping">
                    <i class="fas fa-save me-1"></i> Save Shipping Details
                </button>
            </div>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <?php include('pos/script.php'); ?>
</body>
</html>