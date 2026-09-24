@completing_nmi_held_orders
Feature: Completing a held order whose card was kept for renewals
    In order to take the money for an order that started recurring charges once I have accepted it
    As an Administrator
    I want completing its payment to charge the card kept for its renewals, and keep it for them

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships everywhere for Free
        And the store has an NMI payment method "Card" with a code "nmi_card" that takes payment later
        And the store has a product "PHP T-Shirt"
        And there is a customer "ada@example.com" that placed an order "#00000001"
        And the customer bought a single "PHP T-Shirt"
        And the customer "Ada Lovelace" addressed it to "Seaside Fwy", "90802" "Los Angeles" in the "United States" with identical billing address
        And the customer chose "Free" shipping method with "Card" payment
        And this order is held with a "Visa" card ending "1111" kept for renewals
        And I am logged in as an administrator

    @ui
    Scenario: Completing the payment charges the card kept for renewals
        Given NMI will approve the charge
        And I am viewing the summary of the order "#00000001"
        When I mark this order as paid
        Then I should be notified that the order's payment has been successfully completed
        And it should have payment state "Completed"
        And the administrator should see the payment request with action "Recurring charge" for "Card" payment method and state "Completed"

    @ui
    Scenario: A declined charge leaves the payment waiting
        Given NMI will decline the charge with "DECLINE"
        And I am viewing the summary of the order "#00000001"
        When I mark this order as paid
        Then I should be notified that the card on file was declined with "DECLINE"
        And it should have payment state "Processing"
        And the administrator should see the payment request with action "Recurring charge" for "Card" payment method and state "Failed"
