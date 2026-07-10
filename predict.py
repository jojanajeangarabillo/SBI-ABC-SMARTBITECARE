#!/usr/bin/env python3
"""
predict.py - Inventory Shortage Prediction Script

This script runs predictions for a specific branch using the training dataset.
Expected input: branch_id, forecast_days
Output: JSON with predictions
"""

import sys
import json
import mysql.connector
import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestRegressor
from sklearn.preprocessing import LabelEncoder
from datetime import datetime, timedelta
import warnings
warnings.filterwarnings('ignore')

# ============================================
# CONFIGURATION
# ============================================

DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',  # Your MySQL password
    'database': 'smartbitecare'
}

def get_db_connection():
    """Create database connection"""
    return mysql.connector.connect(**DB_CONFIG)

# ============================================
# PREDICTION ENGINE
# ============================================

def load_training_data(branch_id):
    """Load training dataset from database"""
    conn = get_db_connection()
    cursor = conn.cursor(dictionary=True)
    
    query = """
        SELECT 
            t.*,
            i.item_name,
            i.minimum_stock,
            i.is_predictable
        FROM training_dataset t
        JOIN inventory_items i ON t.item_id = i.item_id
        WHERE t.branch_id = %s AND i.is_predictable = 1
        ORDER BY t.item_id, t.record_date
    """
    
    cursor.execute(query, (branch_id,))
    data = cursor.fetchall()
    cursor.close()
    conn.close()
    
    return pd.DataFrame(data)

def get_current_stock(item_id, branch_id):
    """Get current stock for an item"""
    conn = get_db_connection()
    cursor = conn.cursor(dictionary=True)
    
    query = """
        SELECT COALESCE(SUM(quantity_available), 0) as total_stock
        FROM inventory_stocks
        WHERE item_id = %s AND branch_id = %s AND is_active = 1
    """
    
    cursor.execute(query, (item_id, branch_id))
    result = cursor.fetchone()
    cursor.close()
    conn.close()
    
    return result['total_stock'] if result else 0

def predict_shortages(df, branch_id, forecast_days=30):
    """
    Predict shortages using Random Forest Regression
    
    Returns:
        list: Predictions for each item
    """
    if df.empty:
        return []
    
    predictions = []
    
    # Group by item
    for item_id, group in df.groupby('item_id'):
        item_name = group.iloc[0]['item_name']
        minimum_stock = group.iloc[0]['minimum_stock']
        
        # Need at least 10 records for prediction
        if len(group) < 10:
            continue
        
        # Prepare features
        group = group.sort_values('record_date')
        
        # Features: day_of_week, month, day_of_year, patient_count, lag_1, lag_2, rolling_mean_7
        group['record_date'] = pd.to_datetime(group['record_date'])
        group['day_of_week'] = group['record_date'].dt.dayofweek
        group['month'] = group['record_date'].dt.month
        group['day_of_year'] = group['record_date'].dt.dayofyear
        
        # Create lag features for quantity_used
        group['lag_1'] = group['quantity_used'].shift(1)
        group['lag_2'] = group['quantity_used'].shift(2)
        group['rolling_mean_7'] = group['quantity_used'].rolling(window=7, min_periods=1).mean()
        
        # Drop rows with NaN from lag features
        group = group.dropna()
        
        if len(group) < 10:
            continue
        
        # Features for training
        feature_cols = ['day_of_week', 'month', 'day_of_year', 'patient_count', 
                        'lag_1', 'lag_2', 'rolling_mean_7']
        
        X = group[feature_cols].values
        y = group['quantity_used'].values
        
        # Train model
        model = RandomForestRegressor(
            n_estimators=100,
            max_depth=10,
            random_state=42,
            n_jobs=-1
        )
        model.fit(X, y)
        
        # Predict future consumption
        current_stock = get_current_stock(item_id, branch_id)
        
        # Create future features
        last_date = group['record_date'].max()
        future_dates = [last_date + timedelta(days=i) for i in range(1, forecast_days + 1)]
        
        # Use last known values for future predictions
        last_known = group.iloc[-1]
        avg_patients = group['patient_count'].mean()
        
        # Simple sequential prediction
        predicted_usage = 0
        for i, future_date in enumerate(future_dates):
            # Simple feature generation for future
            features = np.array([[
                future_date.weekday(),
                future_date.month,
                future_date.timetuple().tm_yday,
                avg_patients,
                group.iloc[-1]['quantity_used'],  # lag_1
                group.iloc[-2]['quantity_used'] if len(group) > 1 else group.iloc[-1]['quantity_used'],  # lag_2
                group['quantity_used'].tail(7).mean()  # rolling_mean
            ]])
            
            pred_usage = model.predict(features)[0]
            pred_usage = max(0, pred_usage)  # No negative usage
            predicted_usage += pred_usage
            
            # Update for next iteration
            # Shift values for next prediction
            group = pd.concat([group, pd.DataFrame({
                'quantity_used': [pred_usage],
                'patient_count': [avg_patients],
                'record_date': [future_date]
            })], ignore_index=True)
        
        # Calculate probability of shortage
        remaining_stock = current_stock - predicted_usage
        shortage_prob = 0
        
        if current_stock > 0:
            # If predicted usage exceeds current stock, high probability of shortage
            if remaining_stock < 0:
                shortage_prob = 1.0
            else:
                # Check if remaining stock is below minimum threshold
                if minimum_stock > 0:
                    shortage_prob = max(0, 1 - (remaining_stock / (minimum_stock * 2)))
                else:
                    shortage_prob = max(0, 1 - (remaining_stock / (max(current_stock, 1) * 2)))
        
        # Clamp probability
        shortage_prob = min(1.0, max(0.0, shortage_prob))
        
        predictions.append({
            'item_id': int(item_id),
            'item_name': item_name,
            'current_stock': int(current_stock),
            'minimum_stock': int(minimum_stock),
            'predicted_consumption': int(max(0, predicted_usage)),
            'probability_score': round(shortage_prob, 4)
        })
    
    # Sort by probability score (highest first)
    predictions.sort(key=lambda x: x['probability_score'], reverse=True)
    
    return predictions

# ============================================
# MAIN EXECUTION
# ============================================

def main():
    if len(sys.argv) < 2:
        print(json.dumps({
            'success': False,
            'error': 'Missing branch_id parameter'
        }))
        sys.exit(1)
    
    branch_id = sys.argv[1]
    forecast_days = int(sys.argv[2]) if len(sys.argv) > 2 else 30
    
    try:
        # Load training data
        df = load_training_data(branch_id)
        
        if df.empty:
            print(json.dumps({
                'success': False,
                'error': 'No training data found for this branch'
            }))
            sys.exit(1)
        
        # Run predictions
        predictions = predict_shortages(df, branch_id, forecast_days)
        
        if not predictions:
            print(json.dumps({
                'success': False,
                'error': 'No predictions could be generated. Need at least 10 records per item.'
            }))
            sys.exit(1)
        
        # Output results
        print(json.dumps({
            'success': True,
            'branch_id': branch_id,
            'forecast_days': forecast_days,
            'predictions': predictions,
            'total_items': len(predictions)
        }))
        
    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': str(e)
        }))
        sys.exit(1)

if __name__ == '__main__':
    main()