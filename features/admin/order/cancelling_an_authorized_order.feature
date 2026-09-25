@cancelling_nmi_authorized_orders
Feature: Cancelling an order whose payment is only authorized
    In order not to keep holding a customer's money for an order I will not fulfil
    As an Administrator
    I want cancelling the order to void its authorization at NMI

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships everywhere for Free
        And the store has an NMI payment method "Card" with a code "nmi_card" that authorizes first
        And the store has a product "PHP T-Shirt"
        And there is a customer "ada@example.com" that placed an order "#00000001"
        And the customer bought a single "PHP T-Shirt"
        And the customer "Ada Lovelace" addressed it to "Seaside Fwy", "90802" "Los Angeles" in the "United States" with identical billing address
        And the customer chose "Free" shipping method with "Card" payment
        And this order is authorized by NMI as "12613498544"
        And I am logged in as an administrator

    @ui
    Scenario: Cancelling the order voids its authorization
        Given NMI will approve the void of "12613498544"
        When I view the summary of the order "#00000001"
        And I cancel this order
        Then I should be notified that it has been successfully updated
        And its state should be "Cancelled"
        And it should have payment state "Cancelled"
        And NMI should have voided the authorization "12613498544" once the queued work has run
